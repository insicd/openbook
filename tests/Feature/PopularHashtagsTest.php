<?php

namespace Tests\Feature;

use App\Application\Queries\PopularHashtagsQuery;
use App\Application\Services\PostComposer;
use App\Domain\Accounts\User;
use App\Domain\Events\Event;
use App\Domain\Posts\Hashtag;
use App\Domain\Posts\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

/**
 * Sidebar destra "In tendenza": classifica hashtag da post ed eventi
 * pubblici/unlisted in cache. Vedi {@see PopularHashtagsQuery}.
 */
class PopularHashtagsTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    private function publishPost(User $author, string $body, string $visibility = Post::VISIBILITY_PUBLIC): Post
    {
        return app(PostComposer::class)->compose($author->actor, [
            'body' => $body,
            'visibility' => $visibility,
        ]);
    }

    public function test_it_ranks_hashtags_by_number_of_public_uses(): void
    {
        $alice = $this->createFullAccount('alice');
        $bob = $this->createFullAccount('bob');

        $this->publishPost($alice, 'Bella giornata di #sole');
        $this->publishPost($bob, 'Ancora #sole oggi');
        $this->publishPost($bob, 'Un post su #pioggia');

        $top = app(PopularHashtagsQuery::class)->top();

        $this->assertSame(['sole', 'pioggia'], $top->pluck('name')->all());
        $this->assertSame(2, $top->firstWhere('name', 'sole')->usage_count);
        $this->assertSame(1, $top->firstWhere('name', 'pioggia')->usage_count);
    }

    public function test_it_excludes_followers_only_and_direct_posts(): void
    {
        $alice = $this->createFullAccount('alice');

        $this->publishPost($alice, 'Pubblico su #aperto', Post::VISIBILITY_PUBLIC);
        $this->publishPost($alice, 'Riservato ai follower su #chiuso', Post::VISIBILITY_FOLLOWERS);
        $this->publishPost($alice, 'Diretto su #privato', Post::VISIBILITY_DIRECT);

        $names = app(PopularHashtagsQuery::class)->top()->pluck('name');

        $this->assertTrue($names->contains('aperto'));
        $this->assertFalse($names->contains('chiuso'));
        $this->assertFalse($names->contains('privato'));
    }

    public function test_it_includes_hashtags_from_remote_cached_posts(): void
    {
        $remoteActor = $this->createRemoteActor('remoto');

        $remotePost = Post::query()->create([
            'actor_id' => $remoteActor->id,
            'uri' => $remoteActor->uri.'/posts/1',
            'body' => 'Post remoto su #fediverso',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $hashtag = Hashtag::query()->create(['name' => 'fediverso']);
        $remotePost->hashtags()->attach($hashtag->id);

        $names = app(PopularHashtagsQuery::class)->top()->pluck('name');

        $this->assertTrue($names->contains('fediverso'));
    }

    public function test_it_combines_public_post_and_event_hashtag_uses(): void
    {
        $alice = $this->createFullAccount('eventtrend');
        $this->publishPost($alice, 'Un post su #musica');
        $music = Hashtag::query()->where('name', 'musica')->firstOrFail();
        $festival = Hashtag::query()->create(['name' => 'festival']);
        $event = Event::query()->create([
            'actor_id' => $alice->actor->id,
            'uri' => route('events.show', fake()->uuid()),
            'name' => 'Festival musicale',
            'visibility' => Event::VISIBILITY_PUBLIC,
            'status' => Event::STATUS_SCHEDULED,
            'start_at' => now()->addDay(),
            'published_at' => now(),
        ]);
        $event->hashtags()->attach([$music->id, $festival->id]);

        $top = app(PopularHashtagsQuery::class)->top();

        $this->assertSame(['musica', 'festival'], $top->pluck('name')->all());
        $this->assertSame(2, $top->firstWhere('name', 'musica')->usage_count);
        $this->assertSame(1, $top->firstWhere('name', 'festival')->usage_count);
    }

    public function test_it_excludes_private_cancelled_and_old_events(): void
    {
        $alice = $this->createFullAccount('eventtrendfilters');

        foreach ([
            ['name' => 'visibile', 'visibility' => Event::VISIBILITY_UNLISTED, 'status' => Event::STATUS_SCHEDULED, 'published_at' => now()],
            ['name' => 'privato', 'visibility' => Event::VISIBILITY_DIRECT, 'status' => Event::STATUS_SCHEDULED, 'published_at' => now()],
            ['name' => 'annullato', 'visibility' => Event::VISIBILITY_PUBLIC, 'status' => Event::STATUS_CANCELLED, 'published_at' => now()],
            ['name' => 'vecchio', 'visibility' => Event::VISIBILITY_PUBLIC, 'status' => Event::STATUS_SCHEDULED, 'published_at' => now()->subDays(8)],
        ] as $position => $attributes) {
            $hashtag = Hashtag::query()->create(['name' => $attributes['name']]);
            $event = Event::query()->create([
                'actor_id' => $alice->actor->id,
                'uri' => 'https://events.example/event/'.$position,
                'name' => 'Evento '.$position,
                'visibility' => $attributes['visibility'],
                'status' => $attributes['status'],
                'start_at' => now()->addDay(),
                'published_at' => $attributes['published_at'],
            ]);
            $event->hashtags()->attach($hashtag->id);
        }

        $names = app(PopularHashtagsQuery::class)->top()->pluck('name');

        $this->assertTrue($names->contains('visibile'));
        $this->assertFalse($names->contains('privato'));
        $this->assertFalse($names->contains('annullato'));
        $this->assertFalse($names->contains('vecchio'));
    }

    public function test_the_sidebar_loads_trends_from_an_authenticated_endpoint(): void
    {
        $alice = $this->createFullAccount('alice');

        foreach (range(1, 6) as $i) {
            $this->publishPost($alice, "Post #tag{$i}");
        }

        $response = $this->actingAs($alice)->get('/home');

        $response->assertOk();
        $response->assertSee(__('openbook.sidebar.trending_title', ['days' => '7d']), false);
        $response->assertSee('data-trending-widget', false);
        $response->assertSee(route('hashtags.sidebar'), false);
        $response->assertSee(__('openbook.sidebar.trending_loading'), false);
        $response->assertDontSee('class="ob-hashtag-list"', false);

        $json = $this->getJson(route('hashtags.sidebar'));

        $json->assertOk()
            ->assertJsonCount(5, 'hashtags')
            ->assertJsonPath('has_more', true)
            ->assertJsonPath('hashtags.0.name', 'tag1')
            ->assertJsonPath('hashtags.0.url', route('hashtags.show', 'tag1'))
            ->assertJsonPath('hashtags.0.uses', trans_choice('openbook.sidebar.hashtag_uses', 1, ['count' => '1']));
        $response->assertSee(__('openbook.sidebar.trending_retry'), false);
        $response->assertSee(route('hashtags.index'), false);
    }

    public function test_the_trending_sidebar_endpoint_requires_authentication(): void
    {
        $this->getJson(route('hashtags.sidebar'))->assertUnauthorized();
    }

    public function test_authenticated_pages_do_not_run_the_trending_query_while_rendering(): void
    {
        $alice = $this->createFullAccount('alice');
        $this->publishPost($alice, 'Un post su #veloce');

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->actingAs($alice)->get('/home')->assertOk();

        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
        DB::disableQueryLog();

        $this->assertStringNotContainsString('event_hashtags', $queries);
    }

    public function test_the_sidebar_endpoint_returns_an_empty_state_when_no_hashtag_has_been_used_yet(): void
    {
        $alice = $this->createFullAccount('alice');

        $response = $this->actingAs($alice)->get('/home');

        $response->assertOk();
        $response->assertSee(__('openbook.sidebar.trending_title', ['days' => '7d']), false);
        $response->assertSee(__('openbook.sidebar.no_popular_hashtags'));
        $response->assertSee(__('openbook.nav.trending'), false);
        $response->assertSee(route('hashtags.index'), false);

        $this->getJson(route('hashtags.sidebar'))
            ->assertOk()
            ->assertJsonPath('hashtags', [])
            ->assertJsonPath('has_more', false);
    }

    public function test_the_sidebar_cache_expires_after_five_minutes(): void
    {
        $alice = $this->createFullAccount('trendcache');
        $this->publishPost($alice, 'Post #prima');

        $this->actingAs($alice)->getJson(route('hashtags.sidebar'))
            ->assertOk()
            ->assertJsonPath('hashtags.0.name', 'prima');

        $this->publishPost($alice, 'Post #seconda');

        $this->getJson(route('hashtags.sidebar'))
            ->assertOk()
            ->assertJsonMissing(['name' => 'seconda']);

        $this->travel(5)->minutes();

        $this->getJson(route('hashtags.sidebar'))
            ->assertOk()
            ->assertJsonCount(2, 'hashtags');
    }

    public function test_the_sidebar_cache_works_with_the_database_store(): void
    {
        config(['cache.default' => 'database']);
        $alice = $this->createFullAccount('trenddbcache');
        $this->publishPost($alice, 'Post #prima');
        $query = app(PopularHashtagsQuery::class);

        $this->assertSame(['prima'], $query->sidebar()->pluck('name')->all());

        $this->publishPost($alice, 'Post #seconda');
        $this->assertSame(['prima'], $query->sidebar()->pluck('name')->all());

        $query->invalidateSidebar();
        $this->assertSame(['prima', 'seconda'], $query->sidebar()->pluck('name')->all());
    }

    public function test_visiting_trending_immediately_invalidates_the_sidebar_cache(): void
    {
        $alice = $this->createFullAccount('trendrefresh');
        $this->publishPost($alice, 'Post #prima');

        $this->actingAs($alice)->getJson(route('hashtags.sidebar'))
            ->assertOk()
            ->assertJsonPath('hashtags.0.name', 'prima');

        $this->publishPost($alice, 'Post #seconda');

        $this->getJson(route('hashtags.sidebar'))
            ->assertJsonMissing(['name' => 'seconda']);

        $this->get(route('hashtags.index'))
            ->assertOk()
            ->assertSee('#seconda');

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->getJson(route('hashtags.sidebar'))
            ->assertOk()
            ->assertJsonCount(2, 'hashtags');

        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
        DB::disableQueryLog();

        $this->assertStringNotContainsString('event_hashtags', $queries);
    }

    public function test_changing_moderation_or_trending_window_ignores_a_stale_sidebar_cache(): void
    {
        $alice = $this->createFullAccount('trendpolicy');
        $this->publishPost($alice, 'Post #nudes');
        $old = $this->publishPost($alice, 'Post #antico');
        $old->forceFill(['published_at' => now()->subDays(8)])->save();

        $this->actingAs($alice)->getJson(route('hashtags.sidebar'))
            ->assertOk()
            ->assertJsonPath('hashtags.0.name', 'nudes');

        config()->set('openbook.moderation.hide_content_warnings_from_world', true);
        config()->set('openbook.moderation.forced_content_warning_hashtags', ['nudes']);

        $this->getJson(route('hashtags.sidebar'))
            ->assertOk()
            ->assertJsonPath('hashtags', []);

        config()->set('openbook.hashtags.trending_days', 14);

        $this->getJson(route('hashtags.sidebar'))
            ->assertOk()
            ->assertJsonPath('hashtags.0.name', 'antico')
            ->assertJsonMissing(['name' => 'nudes']);
    }

    public function test_the_trending_index_lists_hashtags(): void
    {
        $alice = $this->createFullAccount('alice');
        $this->publishPost($alice, 'Un post su #laravel');

        $response = $this->actingAs($alice)->get(route('hashtags.index'));

        $response->assertOk();
        $response->assertSee(__('openbook.hashtags.index_title'), false);
        $response->assertSee('#laravel');
    }

    public function test_the_trending_index_shows_follow_state_for_each_hashtag(): void
    {
        $alice = $this->createFullAccount('trendfollow');
        $this->publishPost($alice, 'Post su #musica e #fotografia');
        $music = Hashtag::query()->where('name', 'musica')->firstOrFail();
        $alice->actor->followedHashtags()->attach($music->id);

        $response = $this->actingAs($alice)->get(route('hashtags.index'));

        $response->assertOk();
        $response->assertSee(route('hashtags.unfollow', ['name' => 'musica']), false);
        $response->assertSee(route('hashtags.follow', ['name' => 'fotografia']), false);
    }

    public function test_it_excludes_empty_hashtag_names_from_trending(): void
    {
        $alice = $this->createFullAccount('alice');
        $post = $this->publishPost($alice, 'Post normale su #valido');

        $empty = Hashtag::query()->create(['name' => '']);
        $post->hashtags()->attach($empty->id);

        $names = app(PopularHashtagsQuery::class)->top()->pluck('name');

        $this->assertSame(['valido'], $names->all());
    }

    public function test_forced_content_warning_hashtags_are_hidden_from_trending_when_world_filtering_is_enabled(): void
    {
        config()->set('openbook.moderation.hide_content_warnings_from_world', true);
        config()->set('openbook.moderation.forced_content_warning_hashtags', ['nudes']);
        $alice = $this->createFullAccount('trendmoderation');
        $this->publishPost($alice, 'Contenuto #nudes');
        $this->publishPost($alice, 'Contenuto #innocuo');

        $this->assertSame(['innocuo'], app(PopularHashtagsQuery::class)->top()->pluck('name')->all());

        $this->actingAs($alice)
            ->get(route('hashtags.index'))
            ->assertOk()
            ->assertSee(route('hashtags.show', 'innocuo'), false)
            ->assertDontSee(route('hashtags.show', 'nudes'), false);

        $this->actingAs($alice)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee(route('hashtags.sidebar'), false);

        $this->actingAs($alice)
            ->getJson(route('hashtags.sidebar'))
            ->assertOk()
            ->assertJsonPath('hashtags.0.name', 'innocuo')
            ->assertJsonMissing(['name' => 'nudes']);
    }

    public function test_forced_content_warning_hashtags_remain_in_trending_when_world_filtering_is_disabled(): void
    {
        config()->set('openbook.moderation.hide_content_warnings_from_world', false);
        config()->set('openbook.moderation.forced_content_warning_hashtags', ['nudes']);
        $alice = $this->createFullAccount('trendmoderationoff');
        $this->publishPost($alice, 'Contenuto #nudes');

        $this->assertSame(['nudes'], app(PopularHashtagsQuery::class)->top()->pluck('name')->all());
    }

    public function test_it_ignores_hashtags_outside_the_default_seven_day_window(): void
    {
        $alice = $this->createFullAccount('alice');
        $old = $this->publishPost($alice, 'Vecchio #antico');
        $old->forceFill(['published_at' => now()->subDays(8)])->save();
        $this->publishPost($alice, 'Recente #nuovo');

        $names = app(PopularHashtagsQuery::class)->top()->pluck('name');

        $this->assertTrue($names->contains('nuovo'));
        $this->assertFalse($names->contains('antico'));
    }

    public function test_a_longer_window_includes_older_hashtags(): void
    {
        $alice = $this->createFullAccount('alice');
        $old = $this->publishPost($alice, 'Vecchio #antico');
        $old->forceFill(['published_at' => now()->subDays(8)])->save();

        config(['openbook.hashtags.trending_days' => 14]);

        $names = app(PopularHashtagsQuery::class)->top()->pluck('name');

        $this->assertTrue($names->contains('antico'));
    }

    public function test_the_trending_index_mentions_the_configured_window(): void
    {
        $alice = $this->createFullAccount('alicewindow');
        $this->publishPost($alice, 'Un post su #laravel');

        $this->actingAs($alice)
            ->get(route('hashtags.index'))
            ->assertOk()
            ->assertSee(__('openbook.hashtags.index_subtitle', ['days' => 7]));
    }

    public function test_the_sidebar_title_includes_the_configured_window(): void
    {
        config(['openbook.hashtags.trending_days' => 14]);

        $alice = $this->createFullAccount('alicesidebarwindow');

        $this->actingAs($alice)
            ->get('/home')
            ->assertOk()
            ->assertSee(__('openbook.sidebar.trending_title', ['days' => '14d']), false)
            ->assertDontSee(__('openbook.sidebar.trending_title', ['days' => '7d']), false);
    }

    public function test_the_home_feed_renders_when_an_empty_hashtag_is_attached_to_a_post(): void
    {
        $alice = $this->createFullAccount('alice');
        $post = $this->publishPost($alice, 'Post visibile');

        $empty = Hashtag::query()->create(['name' => '']);
        $post->hashtags()->attach($empty->id);

        $response = $this->actingAs($alice)->get('/home');

        $response->assertOk();
        $response->assertSee('Post visibile');
    }
}
