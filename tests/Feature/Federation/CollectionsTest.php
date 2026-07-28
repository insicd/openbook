<?php

namespace Tests\Feature\Federation;

use App\Application\Services\FollowManager;
use App\Application\Services\PostComposer;
use App\Domain\Posts\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class CollectionsTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_the_outbox_summary_reports_the_total_number_of_public_posts(): void
    {
        $author = $this->createFullAccount('outboxautore');

        app(PostComposer::class)->compose($author->actor, ['body' => 'Pubblico uno.', 'visibility' => Post::VISIBILITY_PUBLIC]);
        app(PostComposer::class)->compose($author->actor, ['body' => 'Non elencato.', 'visibility' => Post::VISIBILITY_UNLISTED]);
        app(PostComposer::class)->compose($author->actor, ['body' => 'Solo follower.', 'visibility' => Post::VISIBILITY_FOLLOWERS]);

        $response = $this->get('/users/outboxautore/outbox');

        $response->assertOk();
        $response->assertJson([
            'type' => 'OrderedCollection',
            'totalItems' => 2,
        ]);
    }

    public function test_the_outbox_first_page_wraps_public_posts_in_create_activities(): void
    {
        $author = $this->createFullAccount('outboxpagina');
        $post = app(PostComposer::class)->compose($author->actor, ['body' => 'Contenuto in outbox.', 'visibility' => Post::VISIBILITY_PUBLIC]);

        $response = $this->get('/users/outboxpagina/outbox?page=1');

        $response->assertOk();
        $response->assertJsonPath('type', 'OrderedCollectionPage');
        $response->assertJsonPath('orderedItems.0.type', 'Create');
        $response->assertJsonPath('orderedItems.0.object.id', url("/posts/{$post->id}"));
    }

    public function test_the_followers_collection_lists_accepted_followers(): void
    {
        $author = $this->createFullAccount('seguito');
        $follower = $this->createFullAccount('seguace');

        app(FollowManager::class)->follow($follower->actor, $author->actor);

        $response = $this->get('/users/seguito/followers?page=1');

        $response->assertOk();
        $response->assertJsonPath('type', 'OrderedCollectionPage');
        $response->assertJsonPath('orderedItems', [$follower->actor->uri]);
    }

    public function test_the_following_collection_lists_accepted_followed_accounts(): void
    {
        $follower = $this->createFullAccount('seguace2');
        $author = $this->createFullAccount('seguito2');

        app(FollowManager::class)->follow($follower->actor, $author->actor);

        $response = $this->get('/users/seguace2/following?page=1');

        $response->assertOk();
        $response->assertJsonPath('orderedItems', [$author->actor->uri]);
    }

    public function test_collections_return_not_found_for_an_unknown_user(): void
    {
        $this->get('/users/nessuno/outbox')->assertNotFound();
        $this->get('/users/nessuno/followers')->assertNotFound();
        $this->get('/users/nessuno/following')->assertNotFound();
    }
}
