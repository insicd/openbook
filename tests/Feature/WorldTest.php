<?php

namespace Tests\Feature;

use App\Application\Queries\FeedQuery;
use App\Application\Queries\PopularRemoteActorsQuery;
use App\Application\Services\FollowManager;
use App\Application\Services\PostComposer;
use App\Domain\Accounts\User;
use App\Domain\Posts\Hashtag;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Serialization\NoteSerializer;
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

    public function test_the_world_feed_can_exclude_posts_with_content_warnings(): void
    {
        config()->set('openbook.moderation.hide_content_warnings_from_world', true);
        $remote = $this->createRemoteActor('worldcw');
        $normal = $this->cacheRemotePost($remote);
        $sensitive = $this->cacheRemotePost($remote, ['content_warning' => 'Nudità']);

        $ids = app(FeedQuery::class)->world()->getCollection()->pluck('id');

        $this->assertTrue($ids->contains($normal->id));
        $this->assertFalse($ids->contains($sensitive->id));
    }

    public function test_forced_hashtags_apply_a_local_warning_and_follow_the_world_setting(): void
    {
        config()->set('openbook.moderation.hide_content_warnings_from_world', false);
        config()->set('openbook.moderation.forced_content_warning_hashtags', ['nudes']);
        $remote = $this->createRemoteActor('worldforcedcw');
        $post = $this->cacheRemotePost($remote);
        $hashtag = Hashtag::query()->create(['name' => 'nudes']);
        $post->hashtags()->attach($hashtag->id);
        $post->load('hashtags');

        $this->assertTrue($post->hasContentWarning());
        $this->assertSame(__('openbook.posts.forced_content_warning'), $post->effectiveContentWarning());
        $this->assertTrue(app(FeedQuery::class)->world()->getCollection()->contains('id', $post->id));

        config()->set('openbook.moderation.hide_content_warnings_from_world', true);

        $this->assertFalse(app(FeedQuery::class)->world()->getCollection()->contains('id', $post->id));
    }

    public function test_a_forced_local_warning_does_not_modify_the_activitypub_object(): void
    {
        config()->set('openbook.moderation.forced_content_warning_hashtags', ['nudes']);
        $author = $this->createFullAccount('localforcedcw');
        $post = $this->publishLocalPost($author, 'Testo locale #nudes')->load('hashtags');

        $this->assertTrue($post->hasContentWarning());

        $note = NoteSerializer::forPost($post);

        $this->assertFalse($note['sensitive']);
        $this->assertArrayNotHasKey('summary', $note);
    }

    public function test_a_guest_cannot_view_the_world_page(): void
    {
        $response = $this->get('/mondo');

        $response->assertRedirect(route('login'));
    }

    public function test_the_world_page_loads_posts_and_suggestions_independently(): void
    {
        $viewer = $this->createFullAccount('worldviewer');
        $remote = $this->createRemoteActor('tancredi');
        $this->cacheRemotePost($remote, ['body' => 'Contenuto visibile nella pagina Mondo.']);

        $page = $this->actingAs($viewer)->get(route('world.index'));
        $page->assertOk();
        $page->assertSee('data-initial-load', false);
        $page->assertSee('data-async-feed', false);
        $page->assertSee(route('world.suggestions'), false);
        $page->assertDontSee('Contenuto visibile nella pagina Mondo.');
        $page->assertDontSee('@tancredi@remoto.example');

        $posts = $this->actingAs($viewer)->get(route('world.index'), [
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
        $posts->assertOk();
        $posts->assertSee('Contenuto visibile nella pagina Mondo.');
        $posts->assertDontSee('data-world-suggestions-result', false);
        $posts->assertDontSee('<!DOCTYPE html>', false);
        $posts->assertDontSee('data-world-suggestions', false);

        $suggestions = $this->actingAs($viewer)->get(route('world.suggestions'));
        $suggestions->assertOk();
        $suggestions->assertSee('@tancredi@remoto.example');
        $suggestions->assertDontSee('Contenuto visibile nella pagina Mondo.');
        $suggestions->assertDontSee('<!DOCTYPE html>', false);
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

    public function test_the_suggestions_request_renders_a_remote_account(): void
    {
        $viewer = $this->createFullAccount('worldsuggestpage');
        $remote = $this->createRemoteActor('yolanda');
        $this->cacheRemotePost($remote);

        $this->actingAs($viewer)
            ->get(route('world.suggestions'))
            ->assertOk()
            ->assertSee('@yolanda@remoto.example');
    }

    public function test_the_suggestions_request_shows_see_more_when_there_are_more_than_five(): void
    {
        $viewer = $this->createFullAccount('worldseemore');

        for ($i = 1; $i <= 6; $i++) {
            $remote = $this->createRemoteActor('remote'.$i, 'fediverse.example');
            $this->cacheRemotePost($remote);
        }

        $this->actingAs($viewer)
            ->get(route('world.suggestions'))
            ->assertOk()
            ->assertSee(__('openbook.world.suggested_more'))
            ->assertSee(route('world.discover'), false);
    }

    public function test_world_scroll_returns_only_later_posts(): void
    {
        config(['openbook.feed.per_page' => 2]);

        $viewer = $this->createFullAccount('worldfeedscroll');
        $remote = $this->createRemoteActor('worldfeedauthor');

        for ($i = 1; $i <= 3; $i++) {
            $this->cacheRemotePost($remote, [
                'body' => 'Post dal mondo '.$i,
                'published_at' => now()->subMinutes($i),
            ]);
        }

        $first = $this->actingAs($viewer)->get(route('world.index'), [
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
        $first->assertOk();
        $first->assertSee('Post dal mondo 1');
        $first->assertDontSee('Post dal mondo 3');
        $this->assertSame(1, preg_match('/data-next-url="([^"]+)"/', $first->getContent(), $matches));

        $second = $this->actingAs($viewer)->get(html_entity_decode($matches[1]), [
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
        $second->assertOk();
        $second->assertSee('Post dal mondo 3');
        $second->assertDontSee('Post dal mondo 1');
        $second->assertDontSee('<!DOCTYPE html>', false);
        $second->assertDontSee('data-next-url=', false);
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

    public function test_the_discover_page_uses_infinite_scroll_markup_when_there_are_more_pages(): void
    {
        config(['openbook.federation.discover_per_page' => 2]);

        $viewer = $this->createFullAccount('worlddiscoverscroll');

        for ($i = 1; $i <= 3; $i++) {
            $remote = $this->createRemoteActor('scroll'.$i, 'fediverse.example');
            $this->cacheRemotePost($remote, ['published_at' => now()->subMinutes($i)]);
        }

        $response = $this->actingAs($viewer)->get(route('world.discover'));

        $response->assertOk();
        $response->assertSee('id="ob-discover-list"', false);
        $response->assertSee('data-infinite-scroll', false);
        $response->assertSee('data-next-url="'.route('world.discover', ['page' => 2]).'"', false);
        $response->assertSee('<noscript>', false);
        $response->assertSee('ob-pagination', false);

        $fragment = $this->actingAs($viewer)->get(route('world.discover', ['page' => 2]), [
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
        $fragment->assertOk();
        $fragment->assertSee('@scroll3@fediverse.example');
        $fragment->assertDontSee('@scroll1@fediverse.example');
        $fragment->assertDontSee('<!DOCTYPE html>', false);
        $fragment->assertDontSee('ob-pagination', false);

        $direct = $this->actingAs($viewer)->get(route('world.discover', ['page' => 2]));
        $direct->assertOk();
        $direct->assertSee('<!DOCTYPE html>', false);
        $direct->assertSee('@scroll3@fediverse.example');
    }

    public function test_a_guest_cannot_view_the_discover_page(): void
    {
        $this->get(route('world.discover'))->assertRedirect(route('login'));
        $this->get(route('world.suggestions'))->assertRedirect(route('login'));
    }
}
