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

    public function test_it_follows_mastodon_style_next_page_when_first_page_is_empty(): void
    {
        $viewer = $this->createFullAccount('lettoremastodon');
        $author = $this->createRemoteActor('mastoauthor');
        $replier = $this->createRemoteActor('mastoreplier', 'reply.example');
        $post = $this->createRemotePost($author, 'masto');

        $firstPage = $post->uri.'/replies?only_other_accounts=false&page=true';
        $nextPage = $post->uri.'/replies?only_other_accounts=true&page=true';
        $reply = $this->replyNote($replier, $post->uri, 'Risposta sulla pagina next', [
            'attributedTo' => ['id' => $replier->uri, 'type' => 'Person'],
        ]);

        Http::fake([
            $post->uri => Http::response([
                'id' => $post->uri,
                'type' => 'Note',
                'attributedTo' => $author->uri,
                'content' => '<p>Post remoto seguito.</p>',
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                'replies' => [
                    'id' => $post->uri.'/replies',
                    'type' => 'Collection',
                    'first' => [
                        'id' => $firstPage,
                        'type' => 'CollectionPage',
                        'next' => $nextPage,
                        'partOf' => $post->uri.'/replies',
                        'items' => [],
                    ],
                ],
            ], 200, ['Content-Type' => 'application/activity+json']),
            $nextPage => Http::response([
                'id' => $nextPage,
                'type' => 'CollectionPage',
                'partOf' => $post->uri.'/replies',
                'items' => [$reply['id']],
            ], 200, ['Content-Type' => 'application/activity+json']),
            $reply['id'] => Http::response($reply, 200, ['Content-Type' => 'application/activity+json']),
        ]);

        $this->actingAs($viewer)->get(route('posts.show', $post))
            ->assertOk()
            ->assertSee('Risposta sulla pagina next', false);

        $this->assertSame(1, Comment::query()->where('post_id', $post->id)->count());
    }

    public function test_it_accepts_gotosocial_style_string_audience_on_replies(): void
    {
        $viewer = $this->createFullAccount('lettoregts');
        $author = $this->createRemoteActor('autoregts');
        $replier = $this->createRemoteActor('replygts', 'gts.example');
        $post = $this->createRemotePost($author, 'gts');

        $reply = $this->replyNote($replier, $post->uri, 'Reply con to stringa', [
            // GoToSocial spesso invia to/cc come stringa singola, non array.
            'to' => 'https://www.w3.org/ns/activitystreams#Public',
            'cc' => $replier->uri.'/followers',
        ]);

        $this->fakeNoteWithReplies($post, [$reply]);

        $this->actingAs($viewer)->get(route('posts.show', $post))
            ->assertOk()
            ->assertSee('Reply con to stringa', false);

        $this->assertSame(1, Comment::query()->where('post_id', $post->id)->count());
    }

    public function test_it_dereferences_collection_id_when_first_is_missing(): void
    {
        $viewer = $this->createFullAccount('lettorecoll');
        $author = $this->createRemoteActor('autorecoll');
        $replier = $this->createRemoteActor('replycoll', 'coll.example');
        $post = $this->createRemotePost($author, 'coll');
        $collectionUrl = $post->uri.'/replies';
        $reply = $this->replyNote($replier, $post->uri, 'Dalla collection id');

        Http::fake([
            $post->uri => Http::response([
                'id' => $post->uri,
                'type' => 'Note',
                'attributedTo' => $author->uri,
                'to' => 'https://www.w3.org/ns/activitystreams#Public',
                'replies' => [
                    'id' => $collectionUrl,
                    'type' => 'Collection',
                    'totalItems' => 1,
                ],
            ], 200),
            $collectionUrl => Http::response([
                'id' => $collectionUrl,
                'type' => 'Collection',
                'items' => [$reply],
            ], 200),
        ]);

        $this->actingAs($viewer)->get(route('posts.show', $post))
            ->assertOk()
            ->assertSee('Dalla collection id', false);
    }

    public function test_opening_a_remote_post_updates_like_and_share_totals_from_the_note(): void
    {
        $viewer = $this->createFullAccount('lettorelikes');
        $author = $this->createRemoteActor('autorelikes');
        $post = $this->createRemotePost($author, 'withcounts');
        $post->forceFill(['likes_count' => 2, 'announces_count' => 1])->save();

        Http::fake([
            $post->uri => Http::response([
                'id' => $post->uri,
                'type' => 'Note',
                'attributedTo' => $author->uri,
                'content' => '<p>Post remoto seguito.</p>',
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                'likes' => [
                    'id' => $post->uri.'/likes',
                    'type' => 'Collection',
                    'totalItems' => 18,
                ],
                'shares' => [
                    'id' => $post->uri.'/shares',
                    'type' => 'Collection',
                    'totalItems' => 5,
                ],
                'replies' => [
                    'id' => $post->uri.'/replies',
                    'type' => 'Collection',
                    'first' => [
                        'type' => 'CollectionPage',
                        'items' => [],
                    ],
                ],
            ], 200, ['Content-Type' => 'application/activity+json']),
        ]);

        $this->actingAs($viewer)->get(route('posts.show', $post))->assertOk();

        $post = $post->fresh();
        $this->assertSame(18, $post->likes_count);
        $this->assertSame(5, $post->announces_count);
        Http::assertNotSent(fn ($request) => $request->url() === $post->uri.'/likes' || $request->url() === $post->uri.'/shares');
    }

    public function test_origin_reaction_totals_do_not_drop_below_local_counts(): void
    {
        $viewer = $this->createFullAccount('lettorefloor');
        $author = $this->createRemoteActor('autorefloor');
        $post = $this->createRemotePost($author, 'floor');
        $post->forceFill(['likes_count' => 9, 'announces_count' => 4])->save();

        Http::fake([
            $post->uri => Http::response([
                'id' => $post->uri,
                'type' => 'Note',
                'attributedTo' => $author->uri,
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                'likes' => ['type' => 'Collection', 'totalItems' => 3],
                'shares' => ['type' => 'Collection', 'totalItems' => 1],
                'replies' => [
                    'id' => $post->uri.'/replies',
                    'type' => 'Collection',
                    'first' => ['type' => 'CollectionPage', 'items' => []],
                ],
            ], 200, ['Content-Type' => 'application/activity+json']),
        ]);

        $this->actingAs($viewer)->get(route('posts.show', $post))->assertOk();

        $post = $post->fresh();
        $this->assertSame(9, $post->likes_count);
        $this->assertSame(4, $post->announces_count);
    }

    public function test_it_fetches_likes_collection_when_the_note_only_has_a_url(): void
    {
        $viewer = $this->createFullAccount('lettorecollikes');
        $author = $this->createRemoteActor('autorecollikes');
        $post = $this->createRemotePost($author, 'collikes');
        $likesUrl = $post->uri.'/likes';

        Http::fake([
            $post->uri => Http::response([
                'id' => $post->uri,
                'type' => 'Note',
                'attributedTo' => $author->uri,
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                'likes' => $likesUrl,
                'replies' => [
                    'id' => $post->uri.'/replies',
                    'type' => 'Collection',
                    'first' => ['type' => 'CollectionPage', 'items' => []],
                ],
            ], 200, ['Content-Type' => 'application/activity+json']),
            $likesUrl => Http::response([
                'id' => $likesUrl,
                'type' => 'OrderedCollection',
                'totalItems' => 27,
            ], 200, ['Content-Type' => 'application/activity+json']),
        ]);

        $this->actingAs($viewer)->get(route('posts.show', $post))->assertOk();

        $this->assertSame(27, $post->fresh()->likes_count);
        Http::assertSent(fn ($request) => $request->url() === $likesUrl);
    }
}
