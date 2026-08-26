<?php

namespace Tests\Feature;

use App\Application\Queries\PopularHashtagsQuery;
use App\Application\Services\PostComposer;
use App\Domain\Accounts\User;
use App\Domain\Posts\Hashtag;
use App\Domain\Posts\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

/**
 * Sidebar destra "In tendenza": classifica hashtag dai post pubblici/unlisted
 * in cache (locali e remoti). Vedi {@see PopularHashtagsQuery}.
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

    public function test_the_sidebar_shows_trending_hashtags_with_limit_and_more_link(): void
    {
        $alice = $this->createFullAccount('alice');

        foreach (range(1, 6) as $i) {
            $this->publishPost($alice, "Post #tag{$i}");
        }

        $response = $this->actingAs($alice)->get('/home');

        $response->assertOk();
        $response->assertSee(__('openbook.sidebar.trending_title'), false);
        $response->assertSee('#tag1');
        $response->assertSee(__('openbook.sidebar.trending_more'), false);
        $response->assertSee(route('hashtags.index'), false);
        $response->assertDontSee(__('openbook.sidebar.no_popular_hashtags'));
    }

    public function test_the_sidebar_shows_an_empty_state_when_no_hashtag_has_been_used_yet(): void
    {
        $alice = $this->createFullAccount('alice');

        $response = $this->actingAs($alice)->get('/home');

        $response->assertOk();
        $response->assertSee(__('openbook.sidebar.trending_title'), false);
        $response->assertSee(__('openbook.sidebar.no_popular_hashtags'));
        $response->assertSee(__('openbook.nav.trending'), false);
        $response->assertSee(route('hashtags.index'), false);
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

    public function test_it_excludes_empty_hashtag_names_from_trending(): void
    {
        $alice = $this->createFullAccount('alice');
        $post = $this->publishPost($alice, 'Post normale su #valido');

        $empty = Hashtag::query()->create(['name' => '']);
        $post->hashtags()->attach($empty->id);

        $names = app(PopularHashtagsQuery::class)->top()->pluck('name');

        $this->assertSame(['valido'], $names->all());
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
