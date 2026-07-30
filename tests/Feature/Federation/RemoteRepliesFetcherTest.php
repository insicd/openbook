<?php

namespace Tests\Feature\Federation;

use App\Domain\Comments\Comment;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

/**
 * Aprendo un post remoto, {@see RemoteRepliesFetcher} interroga la
 * collection "replies" della Note originale per far comparire commenti di
 * terzi che non sono mai stati consegnati all'inbox di questa istanza.
 */
class RemoteRepliesFetcherTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    private function createRemotePost(Actor $author, string $suffix = '1'): Post
    {
        return Post::query()->create([
            'actor_id' => $author->id,
            'uri' => $author->uri.'/statuses/'.$suffix,
            'body' => 'Post remoto seguito.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ]);
    }

    /**
     * @param  list<array<string, mixed>|string>  $replyItems
     */
    private function fakeNoteWithReplies(Post $post, array $replyItems, ?string $repliesPageUrl = null): void
    {
        $repliesPageUrl ??= $post->uri.'/replies?page=1';

        Http::fake([
            $post->uri => Http::response([
                'id' => $post->uri,
                'type' => 'Note',
                'attributedTo' => $post->actor->uri,
                'content' => '<p>Post remoto seguito.</p>',
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                'replies' => [
                    'id' => $post->uri.'/replies',
                    'type' => 'Collection',
                    'first' => $repliesPageUrl,
                ],
            ], 200, ['Content-Type' => 'application/activity+json']),
            $repliesPageUrl => Http::response([
                'id' => $repliesPageUrl,
                'type' => 'CollectionPage',
                'partOf' => $post->uri.'/replies',
                'items' => $replyItems,
            ], 200, ['Content-Type' => 'application/activity+json']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function replyNote(Actor $author, string $inReplyTo, string $content, array $overrides = []): array
    {
        $noteUri = $author->uri.'/statuses/'.uniqid('reply');

        return array_merge([
            'id' => $noteUri,
            'type' => 'Note',
            'attributedTo' => $author->uri,
            'inReplyTo' => $inReplyTo,
            'content' => '<p>'.$content.'</p>',
            'published' => now()->subHour()->toAtomString(),
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
        ], $overrides);
    }

    public function test_opening_a_remote_post_fetches_and_caches_public_replies(): void
    {
        $viewer = $this->createFullAccount('lettore');
        $author = $this->createRemoteActor('autoreseguito');
        $replier = $this->createRemoteActor('commentatore', 'altro.example');
        $post = $this->createRemotePost($author);

        $this->fakeNoteWithReplies($post, [
            $this->replyNote($replier, $post->uri, 'Ciao dal fediverso'),
        ]);

        $response = $this->actingAs($viewer)->get(route('posts.show', $post));

        $response->assertOk();
        $response->assertSee('Ciao dal fediverso', false);

        $this->assertSame(1, Comment::query()->where('post_id', $post->id)->count());
        $this->assertSame(1, $post->fresh()->comments_count);
        $this->assertNotNull($post->fresh()->replies_fetched_at);
        $this->assertSame(0, Notification::query()->count());
    }

    public function test_it_skips_replies_to_other_posts_private_notes_and_respects_ttl(): void
    {
        $viewer = $this->createFullAccount('lettore2');
        $author = $this->createRemoteActor('autore2');
        $replier = $this->createRemoteActor('replierttl', 'x.example');
        $post = $this->createRemotePost($author, 'ttl');

        $this->fakeNoteWithReplies($post, [
            $this->replyNote($replier, 'https://altro.example/statuses/9', 'Risposta a un altro post'),
            $this->replyNote($replier, $post->uri, 'Solo follower', [
                'to' => [$author->uri.'/followers'],
                'cc' => [],
            ]),
            $this->replyNote($replier, $post->uri, 'Questa si vede'),
        ]);

        $this->actingAs($viewer)->get(route('posts.show', $post))->assertOk();

        $this->assertSame(1, Comment::query()->where('post_id', $post->id)->count());
        $this->assertDatabaseHas('comments', ['body' => 'Questa si vede']);
        $this->assertDatabaseMissing('comments', ['body' => 'Risposta a un altro post']);
        $this->assertDatabaseMissing('comments', ['body' => 'Solo follower']);

        Http::fake([
            $post->uri => Http::response(['error' => 'should not be called'], 500),
        ]);

        $this->actingAs($viewer)->get(route('posts.show', $post))->assertOk();
        $this->assertSame(1, Comment::query()->where('post_id', $post->id)->count());
    }

    public function test_it_can_attach_a_reply_to_an_existing_remote_comment(): void
    {
        $viewer = $this->createFullAccount('lettore3');
        $author = $this->createRemoteActor('autore3');
        $replier = $this->createRemoteActor('nested', 'y.example');
        $post = $this->createRemotePost($author, 'nest');

        $parent = Comment::query()->create([
            'post_id' => $post->id,
            'actor_id' => $replier->id,
            'uri' => $replier->uri.'/statuses/parent-c',
            'body' => 'Commento padre.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        $nestedAuthor = $this->createRemoteActor('annidato', 'z.example');

        $this->fakeNoteWithReplies($post, [
            $this->replyNote($nestedAuthor, $parent->uri, 'Risposta annidata'),
        ]);

        $this->actingAs($viewer)->get(route('posts.show', $post))->assertOk()
            ->assertSee('Risposta annidata', false);

        $nested = Comment::query()->where('body', 'Risposta annidata')->firstOrFail();
        $this->assertSame($parent->id, $nested->parent_comment_id);
        $this->assertSame(1, $parent->fresh()->replies_count);
    }

    public function test_local_posts_do_not_trigger_a_replies_fetch(): void
    {
        $author = $this->createFullAccount('localauthor');

        Http::fake();

        $this->actingAs($author)
            ->post(route('posts.store'), [
                'body' => 'Post locale senza fetch replies.',
                'visibility' => Post::VISIBILITY_PUBLIC,
            ])
            ->assertRedirect();

        $post = Post::query()->whereNull('uri')->firstOrFail();

        $this->actingAs($author)->get(route('posts.show', $post))->assertOk();

        Http::assertNothingSent();
        $this->assertNull($post->fresh()->replies_fetched_at);
    }
}
