<?php

namespace Tests\Feature;

use App\Application\Queries\FeedQuery;
use App\Application\Queries\PopularRemoteActorsQuery;
use App\Application\Services\FollowManager;
use App\Application\Services\PostComposer;
use App\Domain\Accounts\User;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class WorldTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    private function publishLocalPost(User $author, string $body, string $visibility = Post::VISIBILITY_PUBLIC): Post
    {
        return app(PostComposer::class)->compose($author->actor, [
            'body' => $body,
            'visibility' => $visibility,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function cacheRemotePost(Actor $author, array $overrides = []): Post
    {
        return Post::query()->create(array_merge([
            'actor_id' => $author->id,
            'uri' => $author->uri.'/posts/'.uniqid(),
            'body' => 'Post remoto in cache.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ], $overrides));
    }

    public function test_the_world_feed_includes_cached_public_remote_posts(): void
    {
        $remote = $this->createRemoteActor('romina');
        $post = $this->cacheRemotePost($remote, ['body' => 'Ciao dal fediverso!']);

        $world = app(FeedQuery::class)->world();

        $this->assertTrue($world->getCollection()->pluck('id')->contains($post->id));
    }

    public function test_the_world_feed_excludes_local_posts(): void
    {
        $user = $this->createFullAccount('worldlocale');
        $localPost = $this->publishLocalPost($user, 'Post locale pubblico.');

        $world = app(FeedQuery::class)->world();

        $this->assertFalse($world->getCollection()->pluck('id')->contains($localPost->id));
    }

    public function test_the_world_feed_excludes_non_public_remote_posts(): void
    {
        $remote = $this->createRemoteActor('sabina');
        $followersOnlyPost = $this->cacheRemotePost($remote, ['visibility' => Post::VISIBILITY_FOLLOWERS]);

        $world = app(FeedQuery::class)->world();

        $this->assertFalse($world->getCollection()->pluck('id')->contains($followersOnlyPost->id));
    }

    public function test_a_guest_cannot_view_the_world_page(): void
    {
        $response = $this->get('/mondo');

        $response->assertRedirect(route('login'));
    }

    public function test_an_authenticated_user_sees_remote_posts_on_the_world_page(): void
    {
        $viewer = $this->createFullAccount('worldviewer');
        $remote = $this->createRemoteActor('tancredi');
        $this->cacheRemotePost($remote, ['body' => 'Contenuto visibile nella pagina Mondo.']);

        $this->actingAs($viewer)
            ->get(route('world.index'))
            ->assertOk()
            ->assertSee('Contenuto visibile nella pagina Mondo.');
    }

    public function test_suggested_actors_rank_local_followers_above_recent_activity_only(): void
    {
        Queue::fake();
        $viewer = $this->createFullAccount('worldsuggest');
        $follower = $this->createFullAccount('worldsuggestfollower');

        $followed = $this->createRemoteActor('uberto');
        $this->cacheRemotePost($followed, ['published_at' => now()->subDays(10)]);
        $followRow = app(FollowManager::class)->follow($follower->actor, $followed);
        // Un follow verso un Actor remoto resta "pending" finche' non arriva
        // un Accept dal server remoto (vedi FollowManager::follow): qui lo
        // simuliamo gia' accettato, cosi' da isolare la sola logica di
        // classifica di PopularRemoteActorsQuery.
        $followRow->update(['status' => Follow::STATUS_ACCEPTED, 'accepted_at' => now()]);

        $onlyActive = $this->createRemoteActor('vittoria');
        $this->cacheRemotePost($onlyActive, ['published_at' => now()]);

        $suggestions = app(PopularRemoteActorsQuery::class)->forViewer($viewer->actor);

        $this->assertSame([$followed->id, $onlyActive->id], $suggestions->pluck('id')->all());
    }

    public function test_suggested_actors_exclude_actors_without_any_local_signal(): void
    {
        $viewer = $this->createFullAccount('worldsuggestnone');
        $unknown = $this->createRemoteActor('walter');

        $suggestions = app(PopularRemoteActorsQuery::class)->forViewer($viewer->actor);

        $this->assertFalse($suggestions->pluck('id')->contains($unknown->id));
    }

    public function test_suggested_actors_exclude_actors_already_followed_by_the_viewer(): void
    {
        Queue::fake();
        $viewer = $this->createFullAccount('worldsuggestfollowed');
        $remote = $this->createRemoteActor('ximena');
        $this->cacheRemotePost($remote);
        app(FollowManager::class)->follow($viewer->actor, $remote);

        $suggestions = app(PopularRemoteActorsQuery::class)->forViewer($viewer->actor);

        $this->assertFalse($suggestions->pluck('id')->contains($remote->id));
    }

    public function test_the_world_page_renders_a_suggested_remote_account(): void
    {
        $viewer = $this->createFullAccount('worldsuggestpage');
        $remote = $this->createRemoteActor('yolanda');
        $this->cacheRemotePost($remote);

        $this->actingAs($viewer)
            ->get(route('world.index'))
            ->assertOk()
            ->assertSee('@yolanda@remoto.example');
    }

    public function test_the_world_page_shows_see_more_when_there_are_more_than_five_suggestions(): void
    {
        $viewer = $this->createFullAccount('worldseemore');

        for ($i = 1; $i <= 6; $i++) {
            $remote = $this->createRemoteActor('remote'.$i, 'fediverse.example');
            $this->cacheRemotePost($remote);
        }

        $this->actingAs($viewer)
            ->get(route('world.index'))
            ->assertOk()
            ->assertSee(__('openbook.world.suggested_more'))
            ->assertSee(route('world.discover'), false);
    }

    public function test_the_discover_page_lists_all_suggested_remote_accounts(): void
    {
        $viewer = $this->createFullAccount('worlddiscover');

        for ($i = 1; $i <= 6; $i++) {
            $remote = $this->createRemoteActor('scopri'.$i, 'fediverse.example');
            $this->cacheRemotePost($remote);
        }

        $response = $this->actingAs($viewer)
            ->get(route('world.discover'))
            ->assertOk()
            ->assertSee(__('openbook.world.discover_title'));

        for ($i = 1; $i <= 6; $i++) {
            $response->assertSee('@scopri'.$i.'@fediverse.example');
        }
    }

    public function test_a_guest_cannot_view_the_discover_page(): void
    {
        $this->get(route('world.discover'))->assertRedirect(route('login'));
    }
}
