<?php

namespace Tests\Feature;

use App\Application\Queries\FeedQuery;
use App\Application\Services\AnnounceManager;
use App\Application\Services\CommunityRegistrar;
use App\Application\Services\InstanceSettings;
use App\Application\Services\PublicWebVisibility;
use App\Domain\Comments\Comment;
use App\Domain\Events\Event;
use App\Domain\Events\EventComment;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Infrastructure\Database\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class PublicWebVisibilityTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    private function makePost(Actor $actor, string $body, array $attributes = []): Post
    {
        return Post::query()->create($attributes + [
            'actor_id' => $actor->id,
            'uri' => $actor->isLocal() ? null : $actor->uri.'/posts/'.fake()->uuid(),
            'body' => $body,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    private function event(Actor $actor, string $name, array $attributes = []): Event
    {
        return Event::query()->create($attributes + [
            'actor_id' => $actor->id,
            'uri' => 'https://events.example/events/'.fake()->uuid(),
            'name' => $name,
            'visibility' => Event::VISIBILITY_PUBLIC,
            'status' => Event::STATUS_SCHEDULED,
            'start_at' => now()->addDay(),
        ]);
    }

    public function test_implicit_defaults_preserve_public_access_without_creating_settings(): void
    {
        Http::fake();
        $local = $this->createFullAccount('visibilitydefault');
        $remote = $this->createRemoteActor('visibilityremote');
        $post = $this->makePost($remote, 'Federated public body');
        $before = SystemSetting::query()->count();
        $this->get(route('posts.show', $post))->assertOk()->assertSee('Federated public body');
        $this->get(route('profile.show', $local->username))->assertOk();
        $this->get(route('hashtags.index'))->assertOk();
        $this->get(route('hashtags.show', 'example'))->assertOk();
        $this->assertSame($before, SystemSetting::query()->count());
        $this->assertNull(SystemSetting::get(PublicWebVisibility::KEY_MODE));
        $this->assertNull(SystemSetting::get(PublicWebVisibility::KEY_HASHTAGS));
    }

    public function test_local_mode_guards_direct_pages_and_comments_by_the_root_author(): void
    {
        Http::fake();
        $local = $this->createFullAccount('visibilityroots');
        $remote = $this->createRemoteActor('visibilityrootsremote');
        $localPost = $this->makePost($local->actor, 'Allowed local root');
        $remotePost = $this->makePost($remote, 'Hidden remote root');
        $localComment = Comment::query()->create(['post_id' => $remotePost->id, 'actor_id' => $local->actor->id, 'body' => 'Local reply to hidden remote root', 'status' => 'published']);
        $remoteComment = Comment::query()->create(['post_id' => $localPost->id, 'actor_id' => $remote->id, 'body' => 'Remote reply to allowed local root', 'status' => 'published']);
        $localEvent = $this->event($local->actor, 'Allowed local event');
        $remoteEvent = $this->event($remote, 'Hidden remote event');
        $eventComment = EventComment::query()->create(['event_id' => $remoteEvent->id, 'actor_id' => $local->actor->id, 'body' => 'Hidden event reply', 'status' => 'published']);
        $allowedEventComment = EventComment::query()->create(['event_id' => $localEvent->id, 'actor_id' => $remote->id, 'body' => 'Allowed remote event reply', 'status' => 'published']);
        SystemSetting::put(PublicWebVisibility::KEY_MODE, PublicWebVisibility::LOCAL);

        $this->get(route('posts.show', $localPost))->assertOk()->assertSee($remoteComment->body);
        $this->get(route('comments.show', $remoteComment))->assertOk()->assertSee($remoteComment->body);
        $this->get(route('events.show', $localEvent))->assertOk()->assertSee($allowedEventComment->body);
        $this->get(route('event-comments.show', $allowedEventComment))->assertRedirect(route('events.show', $localEvent).'#commento-evento-'.$allowedEventComment->id);
        $this->get(route('hashtags.show', 'example'))->assertRedirect(route('login'));
        foreach ([route('posts.show', $remotePost), route('comments.show', $localComment), route('events.show', $remoteEvent), route('event-comments.show', $eventComment), route('posts.likes', $remotePost), route('posts.announces', $remotePost)] as $url) {
            $this->get($url)->assertRedirect(route('login'))->assertDontSee($remotePost->body)->assertDontSee($localComment->body);
            $this->get($url, ['X-Requested-With' => 'XMLHttpRequest'])->assertUnauthorized()->assertDontSee($remotePost->body);
        }
        Http::assertNothingSent();
        $this->actingAs($local)->get(route('posts.show', $remotePost))->assertOk()->assertSee($remotePost->body);
    }

    public function test_local_mode_filters_lists_before_pagination_and_hides_remote_quotes(): void
    {
        Http::fake();
        config(['openbook.feed.per_page' => 1]);
        $local = $this->createFullAccount('visibilitylists');
        $remote = $this->createRemoteActor('visibilitylistsremote');
        $remotePost = $this->makePost($remote, 'Secret remote quote');
        $post = $this->makePost($local->actor, 'Visible local card', ['quoted_post_id' => $remotePost->id]);
        app(AnnounceManager::class)->announce($local->actor, $remotePost);
        $localEvent = $this->event($local->actor, 'Visible local event');
        $remoteEvent = $this->event($remote, 'Hidden remote attribution');
        $remoteEvent->attributions()->attach($local->actor);
        SystemSetting::putBool(InstanceSettings::KEY_GUEST_HOME_FEED, true);
        SystemSetting::put(PublicWebVisibility::KEY_MODE, PublicWebVisibility::LOCAL);
        foreach ([route('home'), route('profile.show', $local->username)] as $url) {
            $response = $this->get($url)->assertOk()->assertSee($post->body)->assertDontSee($remotePost->body);
            $this->assertFalse($response->viewData('posts')->hasMorePages());
        }
        $this->get(route('home'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertSee($post->body)->assertDontSee($remotePost->body);
        $this->get(route('posts.show', $post))->assertOk()->assertSee($post->body)->assertDontSee($remotePost->body);
        foreach ([route('events.index'), route('profile.events', $local->username)] as $url) {
            $response = $this->get($url)->assertOk()->assertSee($localEvent->name)->assertDontSee($remoteEvent->name);
            $this->assertSame(1, $response->viewData('events')->total());
        }
        // Shared query consumers (REST/federation) keep their existing default scope.
        config(['openbook.feed.per_page' => 20]);
        $all = app(FeedQuery::class)->forProfile($local->actor, null);
        $this->assertTrue($all->getCollection()->contains('id', $remotePost->id));
    }

    public function test_community_wall_filters_remote_authors_and_directory_stays_local(): void
    {
        $local = $this->createFullAccount('visibilitycommunity');
        $community = app(CommunityRegistrar::class)->register($local, ['slug' => 'visibility', 'name' => 'Visible community']);
        $remote = $this->createRemoteActor('visibilitycommunityremote');
        $localPost = $this->makePost($local->actor, 'Local community message', ['community_id' => $community->id]);
        $remotePost = $this->makePost($remote, 'Remote community message', ['community_id' => $community->id]);
        SystemSetting::put(PublicWebVisibility::KEY_MODE, PublicWebVisibility::LOCAL);
        foreach ([[], ['X-Requested-With' => 'XMLHttpRequest']] as $headers) {
            $this->get(route('communities.show', $community), $headers)->assertOk()->assertSee($localPost->body)->assertDontSee($remotePost->body);
            $this->get(route('communities.index', ['scope' => 'remote']), $headers)->assertOk()->assertViewHas('scope', 'local');
        }
    }

    public function test_closed_mode_protects_content_pages_but_keeps_public_information_and_federation(): void
    {
        $local = $this->createFullAccount('visibilityclosed');
        $post = $this->makePost($local->actor, 'Closed local body');
        $comment = Comment::query()->create(['post_id' => $post->id, 'actor_id' => $local->actor->id, 'body' => 'Closed local reply', 'status' => 'published']);
        $event = $this->event($local->actor, 'Closed local event');
        $community = app(CommunityRegistrar::class)->register($local, ['slug' => 'closedvisibility', 'name' => 'Closed community']);
        SystemSetting::put(PublicWebVisibility::KEY_MODE, PublicWebVisibility::CLOSED);
        SystemSetting::putBool(InstanceSettings::KEY_GUEST_HOME_FEED, true);
        foreach ([route('posts.show', $post), route('comments.show', $comment), route('events.show', $event), route('events.index'), route('events.archive'), route('profile.show', $local->username), route('profile.redirect', $local->username), route('profile.photos', $local->username), route('profile.events', $local->username), route('profile.followers', $local), route('profile.following', $local), route('communities.index'), route('communities.show', $community), route('communities.members', $community), route('hashtags.index'), route('hashtags.show', 'example')] as $url) {
            $this->get($url)->assertRedirect(route('login'))->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->get(route('home'))->assertOk()->assertDontSee($post->body)->assertDontSee('ob-guest-home-layout', false);
        $this->get(route('home'), ['X-Requested-With' => 'XMLHttpRequest'])->assertNotFound();
        foreach ([route('login'), '/regole', '/privacy'] as $url) {
            $this->get($url)->assertOk();
        }
        $ap = ['Accept' => 'application/activity+json'];
        foreach ([route('posts.show', $post), route('comments.show', $comment), route('events.show', $event), route('profile.redirect', $local->username)] as $url) {
            $response = $this->get($url, $ap)->assertOk();
            $this->assertStringStartsWith('application/activity+json', $response->headers->get('Content-Type'));
        }
        $this->get(route('profile.show', $local->username), $ap)->assertRedirect($local->actor->uri);
        $this->get(route('communities.show', $community), $ap)->assertRedirect($community->actor->uri);
        foreach ([route('hashtags.index'), route('events.index'), route('posts.likes', $post)] as $url) {
            $this->get($url, $ap)->assertUnauthorized();
        }
        $this->actingAs($local)->get(route('posts.show', $post))->assertOk()->assertSee($post->body);
    }

    public function test_hashtag_setting_only_restricts_guests_and_private_posts_still_stay_private(): void
    {
        $local = $this->createFullAccount('visibilitytags');
        $post = $this->makePost($local->actor, 'Private post', ['visibility' => Post::VISIBILITY_DIRECT]);
        foreach ([PublicWebVisibility::ALL, PublicWebVisibility::LOCAL, PublicWebVisibility::CLOSED] as $mode) {
            SystemSetting::put(PublicWebVisibility::KEY_MODE, $mode);
            $this->get(route('posts.show', $post))->assertNotFound();
        }
        SystemSetting::put(PublicWebVisibility::KEY_MODE, PublicWebVisibility::ALL);
        SystemSetting::putBool(PublicWebVisibility::KEY_HASHTAGS, false);
        foreach ([route('hashtags.index'), route('hashtags.show', 'example')] as $url) {
            $this->get($url)->assertRedirect(route('login'));
            $this->getJson($url)->assertUnauthorized();
            $this->actingAs($local)->get($url)->assertOk();
            auth()->logout();
        }
        SystemSetting::put(PublicWebVisibility::KEY_MODE, 'invalid');
        $this->get(route('profile.show', $local->username))->assertRedirect(route('login'));
    }
}
