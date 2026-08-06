<?php

namespace Tests\Feature\Federation;

use App\Application\Services\PostComposer;
use App\Domain\Comments\Comment;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class RemotePostRefreshTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    private function createRemotePost(Actor $author, string $suffix = '1'): Post
    {
        return Post::query()->create([
            'actor_id' => $author->id,
            'uri' => $author->uri.'/statuses/'.$suffix,
            'body' => 'Versione precedente del post remoto.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
            'replies_fetched_at' => now(),
        ]);
    }

    public function test_remote_post_menu_offers_fetch_updates_for_authenticated_users(): void
    {
        $viewer = $this->createFullAccount('refreshviewer');
        $author = $this->createRemoteActor('refreshauthor');
        $post = $this->createRemotePost($author);

        $this->actingAs($viewer)
            ->get(route('world.index'))
            ->assertOk()
            ->assertSee(__('openbook.posts.fetch_updates'), false)
            ->assertSee(route('posts.fetch_updates', $post), false);
    }

    public function test_local_posts_do_not_offer_fetch_updates(): void
    {
        $author = $this->createFullAccount('refreshlocal');
        app(PostComposer::class)->compose($author->actor, [
            'body' => 'Post locale senza refresh remoto.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $this->actingAs($author)
            ->get(route('feed.index'))
            ->assertOk()
            ->assertDontSee(__('openbook.posts.fetch_updates'), false);
    }

    public function test_fetch_updates_refreshes_body_and_replies_ignoring_ttl(): void
    {
        $viewer = $this->createFullAccount('refreshforce');
        $author = $this->createRemoteActor('refreshforceauthor');
        $replier = $this->createRemoteActor('refreshreplier', 'altro.example');
        $post = $this->createRemotePost($author, 'force');

        $repliesPageUrl = $post->uri.'/replies?page=1';
        $replyUri = $replier->uri.'/statuses/new-reply';

        Http::fake([
            $post->uri => Http::response([
                'id' => $post->uri,
                'type' => 'Note',
                'attributedTo' => $author->uri,
                'content' => '<p>Testo aggiornato dal server remoto.</p>',
                'published' => now()->subDay()->toAtomString(),
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
                'items' => [[
                    'id' => $replyUri,
                    'type' => 'Note',
                    'attributedTo' => $replier->uri,
                    'inReplyTo' => $post->uri,
                    'content' => '<p>Nuovo commento remoto.</p>',
                    'published' => now()->subHour()->toAtomString(),
                    'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                ]],
            ], 200, ['Content-Type' => 'application/activity+json']),
        ]);

        $this->actingAs($viewer)
            ->from(route('posts.show', $post))
            ->post(route('posts.fetch_updates', $post))
            ->assertRedirect(route('posts.show', $post))
            ->assertSessionHas('status', __('openbook.posts.updates_fetched'));

        $this->assertSame('Testo aggiornato dal server remoto.', $post->fresh()->body);
        $this->assertDatabaseHas('comments', [
            'post_id' => $post->id,
            'uri' => $replyUri,
            'body' => 'Nuovo commento remoto.',
        ]);
        $this->assertSame(1, Comment::query()->where('post_id', $post->id)->count());
    }

    public function test_fetch_updates_with_own_local_reply_in_remote_collection_does_not_error(): void
    {
        $viewer = $this->createFullAccount('localreplyrefresh');
        // URI legacy /@user: prima del repair, resolveByUri non riconosceva
        // l'attore locale e tentava di creare un duplicato → UniqueConstraint.
        $viewer->actor->forceFill([
            'uri' => url('/@'.$viewer->actor->preferred_username),
        ])->saveQuietly();

        $author = $this->createRemoteActor('refreshwithlocal');
        $post = $this->createRemotePost($author, 'with-local');

        $localComment = Comment::query()->create([
            'post_id' => $post->id,
            'actor_id' => $viewer->actor->id,
            'body' => 'Commento fatto da questa istanza.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        $localCommentUri = url('/comments/'.$localComment->id);
        $localActorApId = url('/users/'.$viewer->actor->preferred_username);
        $repliesPageUrl = $post->uri.'/replies?page=1';
        $otherReplier = $this->createRemoteActor('altroreply', 'altro.example');
        $otherReplyUri = $otherReplier->uri.'/statuses/other-reply';

        Http::fake([
            $post->uri => Http::response([
                'id' => $post->uri,
                'type' => 'Note',
                'attributedTo' => $author->uri,
                'content' => '<p>Post remoto.</p>',
                'published' => now()->subDay()->toAtomString(),
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
                'items' => [
                    [
                        'id' => $localCommentUri,
                        'type' => 'Note',
                        'attributedTo' => $localActorApId,
                        'inReplyTo' => $post->uri,
                        'content' => '<p>Commento fatto da questa istanza.</p>',
                        'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                    ],
                    [
                        'id' => $otherReplyUri,
                        'type' => 'Note',
                        'attributedTo' => $otherReplier->uri,
                        'inReplyTo' => $post->uri,
                        'content' => '<p>Altro commento remoto.</p>',
                        'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                    ],
                ],
            ], 200, ['Content-Type' => 'application/activity+json']),
            // Non deve essere chiamato: il dominio locale e' bloccato nel resolver.
            $localActorApId => Http::response(['error' => 'should not fetch'], 500),
        ]);

        $this->actingAs($viewer)
            ->from(route('posts.show', $post))
            ->post(route('posts.fetch_updates', $post))
            ->assertRedirect(route('posts.show', $post))
            ->assertSessionHas('status', __('openbook.posts.updates_fetched'));

        $this->assertSame(1, Comment::query()->where('post_id', $post->id)->where('actor_id', $viewer->actor->id)->count());
        $this->assertDatabaseHas('comments', [
            'post_id' => $post->id,
            'uri' => $otherReplyUri,
            'body' => 'Altro commento remoto.',
        ]);
        $this->assertSame(1, Actor::query()->where('preferred_username', $viewer->actor->preferred_username)->count());
    }

    public function test_guests_cannot_fetch_updates(): void
    {
        $author = $this->createRemoteActor('refreshguest');
        $post = $this->createRemotePost($author, 'guest');

        $this->post(route('posts.fetch_updates', $post))
            ->assertRedirect(route('login'));
    }

    public function test_fetch_updates_rejects_local_posts(): void
    {
        $author = $this->createFullAccount('refreshreject');
        $post = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Locale.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $this->actingAs($author)
            ->post(route('posts.fetch_updates', $post))
            ->assertNotFound();
    }
}
