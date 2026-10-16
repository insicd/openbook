<?php

namespace Tests\Feature;

use App\Application\Queries\FeedQuery;
use App\Application\Services\InstanceSettings;
use App\Application\Services\PostComposer;
use App\Domain\Accounts\User;
use App\Domain\Posts\Hashtag;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Infrastructure\Database\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class HomeGuestFeedTest extends TestCase
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

    private function enableGuestHomeFeed(): void
    {
        SystemSetting::putBool(InstanceSettings::KEY_GUEST_HOME_FEED, true);
    }

    public function test_guest_home_keeps_the_centred_layout_when_the_feed_is_disabled(): void
    {
        $author = $this->createFullAccount('homefeedoff');
        $this->publishLocalPost($author, 'Post pubblico nascosto sulla home classica.');

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee(__('openbook.home.software_title'), false);
        $response->assertDontSee('ob-guest-home-feed', false);
        $response->assertDontSee('ob-guest-home-layout', false);
        $response->assertDontSee('ob-guest-home-toggle', false);
        $response->assertDontSee(__('openbook.home.feed_title', ['name' => config('app.name')]), false);
        $response->assertDontSee('Post pubblico nascosto sulla home classica.');
    }

    public function test_guest_home_ajax_is_not_available_when_the_feed_is_disabled(): void
    {
        $this->get(route('home'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertNotFound();
    }

    public function test_guest_home_feed_shows_public_local_and_cached_remote_posts(): void
    {
        $this->enableGuestHomeFeed();

        $author = $this->createFullAccount('homefeedon');
        $this->publishLocalPost($author, 'Ciao dalla piazza locale.');
        $this->publishLocalPost($author, 'Non elencato sulla home.', Post::VISIBILITY_UNLISTED);
        $this->publishLocalPost($author, 'Solo per i follower.', Post::VISIBILITY_FOLLOWERS);

        $remote = $this->createRemoteActor('romina');
        $this->cacheRemotePost($remote, ['body' => 'Ciao dal fediverso in cache.']);

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('ob-guest-home-feed', false);
        $response->assertSee('ob-guest-home-layout', false);
        $response->assertSee('id="ob-guest-home-toggle"', false);
        $response->assertSee('id="ob-guest-home-aside"', false);
        $response->assertSee(__('openbook.home.toggle_intro'), false);
        $response->assertSee(__('openbook.home.feed_title', ['name' => config('app.name')]));
        $response->assertSee(__('openbook.home.software_title'), false);
        $response->assertSee(__('openbook.home.cta_register'), false);
        $response->assertSee('Ciao dalla piazza locale.');
        $response->assertSee('Ciao dal fediverso in cache.');
        $response->assertDontSee('Non elencato sulla home.');
        $response->assertDontSee('Solo per i follower.');
    }

    public function test_guest_home_feed_ajax_returns_the_next_fragment_without_the_layout(): void
    {
        config(['openbook.feed.per_page' => 2]);
        $this->enableGuestHomeFeed();

        $author = $this->createFullAccount('homefeedajax');
        $this->publishLocalPost($author, 'Post home uno.');
        $this->publishLocalPost($author, 'Post home due.');
        $this->publishLocalPost($author, 'Post home tre.');

        $page = $this->get(route('home'));
        $page->assertOk();
        $page->assertSee('data-infinite-scroll', false);
        $page->assertSee('data-next-url="', false);
        $page->assertSee('id="ob-guest-home-aside"', false);

        $fragment = $this->get(route('home'), ['X-Requested-With' => 'XMLHttpRequest']);
        $fragment->assertOk();
        $fragment->assertSee('id="ob-post-list"', false);
        $fragment->assertSee('data-next-url="', false);
        $fragment->assertDontSee('id="ob-guest-home-aside"', false);
        $fragment->assertDontSee(__('openbook.home.software_title'), false);
    }

    public function test_instance_public_feed_hides_remote_content_warnings_only(): void
    {
        config()->set('openbook.moderation.hide_content_warnings_from_world', true);
        $this->enableGuestHomeFeed();

        $author = $this->createFullAccount('homefeedcw');
        $localSensitive = $this->publishLocalPost($author, 'Avviso locale.');
        $localSensitive->update(['content_warning' => 'Locale']);

        $remote = $this->createRemoteActor('homecw');
        $remoteOk = $this->cacheRemotePost($remote, ['body' => 'Remoto senza avviso.']);
        $remoteSensitive = $this->cacheRemotePost($remote, [
            'body' => 'Remoto con avviso.',
            'content_warning' => 'Nudità',
        ]);

        $ids = app(FeedQuery::class)->instancePublic()->getCollection()->pluck('id');

        $this->assertTrue($ids->contains($localSensitive->id));
        $this->assertTrue($ids->contains($remoteOk->id));
        $this->assertFalse($ids->contains($remoteSensitive->id));
    }

    public function test_instance_public_feed_hides_remote_forced_hashtags_with_world_setting(): void
    {
        config()->set('openbook.moderation.hide_content_warnings_from_world', true);
        config()->set('openbook.moderation.forced_content_warning_hashtags', ['nudes']);

        $remote = $this->createRemoteActor('homeforcedcw');
        $post = $this->cacheRemotePost($remote, ['body' => 'Hashtag forzato remoto.']);
        $hashtag = Hashtag::query()->create(['name' => 'nudes']);
        $post->hashtags()->attach($hashtag->id);

        $this->assertFalse(
            app(FeedQuery::class)->instancePublic()->getCollection()->contains('id', $post->id),
        );
    }
}
