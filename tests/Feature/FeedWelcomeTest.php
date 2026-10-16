<?php

namespace Tests\Feature;

use App\Application\Queries\FeedCursor;
use App\Application\Services\PostComposer;
use App\Domain\Posts\Hashtag;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class FeedWelcomeTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_empty_feed_shows_a_welcome_kit_with_staff_local_and_remote_suggestions(): void
    {
        $admin = $this->createFullAccount('kitadmin');
        $admin->forceFill(['is_admin' => true])->save();

        $local = $this->createFullAccount('kitlocale');
        $local->profile->update(['display_name' => 'Lucia Locale']);

        $remote = $this->createRemoteActor('fediverso', 'social.example', [
            'name' => 'Remo Remoto',
        ]);

        Post::query()->create([
            'actor_id' => $remote->id,
            'uri' => $remote->uri.'/posts/welcome-kit',
            'body' => 'Ciao dal fediverso.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $newbie = $this->createFullAccount('kitnewbie');

        $this->actingAs($newbie)
            ->get(route('feed.index'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee(__('openbook.feed.welcome_title'), false)
            ->assertSee(__('openbook.feed.welcome_staff'), false)
            ->assertSee('@kitadmin', false)
            ->assertSee(__('openbook.feed.welcome_local'), false)
            ->assertSee('Lucia Locale', false)
            ->assertSee(__('openbook.feed.welcome_remote'), false)
            ->assertSee('Remo Remoto', false)
            ->assertSee(route('communities.index'), false)
            ->assertSee(route('world.discover'), false)
            ->assertSeeInOrder([
                'id="ob-welcome-staff"',
                'href="'.route('communities.index').'"',
                'href="'.route('hashtags.index').'"',
                'id="ob-welcome-local"',
                'id="ob-welcome-remote"',
                'href="'.route('world.discover').'"',
            ], false)
            ->assertSee('id="ob-post-list"', false);
    }

    public function test_welcome_kit_precedes_posts_while_the_user_is_getting_started(): void
    {
        $user = $this->createFullAccount('kitposter');

        app(PostComposer::class)->compose($user->actor, [
            'body' => 'Il mio primo post.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $this->actingAs($user)
            ->get(route('feed.index'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSeeInOrder(['class="ob-card ob-welcome"', 'Il mio primo post.'], false)
            ->assertSee('Il mio primo post.', false)
            ->assertSee('id="ob-post-list"', false);
    }

    public function test_welcome_kit_does_not_suggest_accounts_already_followed(): void
    {
        $admin = $this->createFullAccount('kitalready');
        $admin->forceFill(['is_admin' => true])->save();

        $newbie = $this->createFullAccount('kitfollower');

        Follow::query()->create([
            'follower_id' => $newbie->actor->id,
            'following_id' => $admin->actor->id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);

        $this->actingAs($newbie)
            ->get(route('feed.index'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee(__('openbook.feed.welcome_title'), false)
            ->assertDontSee('@kitalready', false);
    }

    #[DataProvider('welcomeLocales')]
    public function test_empty_feed_explains_hashtags_and_links_to_trending_topics(string $locale, string $body, string $label): void
    {
        $newbie = $this->createFullAccount('kitlanguage');
        $newbie->settings()->update(['locale' => $locale]);

        $this->actingAs($newbie)
            ->get(route('feed.index'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee($body)
            ->assertSee('href="'.route('hashtags.index').'"', false)
            ->assertSee($label);
    }

    public static function welcomeLocales(): array
    {
        return [
            'Italian' => ['it', 'Il tuo feed prende forma con ciò che ti interessa: segui persone e argomenti tramite hashtag, oppure pubblica il tuo primo post.', 'Argomenti in tendenza'],
            'English' => ['en', 'Your feed takes shape around your interests: follow people and topics through hashtags, or publish your first post.', 'Trending topics'],
        ];
    }

    #[DataProvider('firstFollowTypes')]
    public function test_first_follow_hides_remote_suggestions_but_keeps_discovery_and_posts(string $type): void
    {
        $viewer = $this->createFullAccount('firstfollowviewer');
        $remote = $this->createRemoteActor('remotesuggestion', 'social.example', ['name' => 'Suggested Remote']);
        Post::query()->create([
            'actor_id' => $remote->id,
            'uri' => $remote->uri.'/posts/suggestion',
            'body' => 'Post remoto per suggerimenti.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        if ($type === 'person') {
            $person = $this->createFullAccount('firstfollowperson');
            Follow::query()->create([
                'follower_id' => $viewer->actor->id,
                'following_id' => $person->actor->id,
                'status' => Follow::STATUS_ACCEPTED,
                'requested_at' => now(),
                'accepted_at' => now(),
            ]);
        } else {
            $tag = Hashtag::query()->create(['name' => 'firstfollow']);
            $viewer->actor->followedHashtags()->attach($tag->id);
        }
        app(PostComposer::class)->compose($viewer->actor, ['body' => 'Post visibile durante i primi passi.']);

        $this->actingAs($viewer)
            ->get(route('feed.index'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('class="ob-card ob-welcome"', false)
            ->assertDontSee('id="ob-welcome-remote"', false)
            ->assertSee('href="'.route('world.discover').'"', false)
            ->assertSeeInOrder(['class="ob-card ob-welcome"', 'Post visibile durante i primi passi.'], false);
    }

    public static function firstFollowTypes(): array
    {
        return ['person' => ['person'], 'hashtag' => ['hashtag']];
    }

    #[DataProvider('followThresholds')]
    public function test_welcome_visibility_depends_on_people_and_hashtags(int $people, int $hashtags, bool $visible): void
    {
        $viewer = $this->createFullAccount('thresholdviewer');
        for ($i = 0; $i < $people; $i++) {
            $actor = $i === 0
                ? $this->createFullAccount('thresholdlocal')->actor
                : $this->createRemoteActor('threshold'.$i);
            Follow::query()->create([
                'follower_id' => $viewer->actor->id,
                'following_id' => $actor->id,
                'status' => Follow::STATUS_ACCEPTED,
                'requested_at' => now(),
                'accepted_at' => now(),
            ]);
        }
        for ($i = 0; $i < $hashtags; $i++) {
            $tag = Hashtag::query()->create(['name' => 'threshold'.$i]);
            $viewer->actor->followedHashtags()->attach($tag->id);
        }

        $response = $this->actingAs($viewer)
            ->get(route('feed.index'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk();

        if ($visible) {
            $response->assertSee('class="ob-card ob-welcome"', false);
        } else {
            $response->assertDontSee('class="ob-card ob-welcome"', false);
        }
    }

    public static function followThresholds(): array
    {
        return [
            'nine people' => [9, 0, true],
            'ten people' => [10, 0, false],
            'nine hashtags' => [0, 9, true],
            'ten hashtags' => [0, 10, false],
            'nine combined' => [4, 5, true],
            'ten combined' => [4, 6, false],
        ];
    }

    public function test_pending_follows_and_communities_do_not_count_as_followed_people(): void
    {
        $viewer = $this->createFullAccount('pendingviewer');
        $person = $this->createRemoteActor('pendingperson');
        $group = $this->createRemoteActor('followedgroup', 'remoto.example', ['type' => Actor::TYPE_GROUP]);
        foreach ([[$person, Follow::STATUS_PENDING], [$group, Follow::STATUS_ACCEPTED]] as [$actor, $status]) {
            Follow::query()->create([
                'follower_id' => $viewer->actor->id,
                'following_id' => $actor->id,
                'status' => $status,
                'requested_at' => now(),
                'accepted_at' => $status === Follow::STATUS_ACCEPTED ? now() : null,
            ]);
        }
        for ($i = 0; $i < 9; $i++) {
            $tag = Hashtag::query()->create(['name' => 'pending'.$i]);
            $viewer->actor->followedHashtags()->attach($tag->id);
        }

        $this->actingAs($viewer)
            ->get(route('feed.index'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('class="ob-card ob-welcome"', false);
    }

    public function test_welcome_kit_is_not_repeated_on_subsequent_feed_pages(): void
    {
        $viewer = $this->createFullAccount('paginationviewer');
        $older = app(PostComposer::class)->compose($viewer->actor, ['body' => 'Post precedente.']);
        $older->update(['published_at' => now()->subHour()]);
        $newer = app(PostComposer::class)->compose($viewer->actor, ['body' => 'Post recente.']);
        $cursor = FeedCursor::fromPost($newer, useShareSort: true);

        $this->actingAs($viewer)
            ->get(route('feed.index', ['cursor' => $cursor->encode()]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('Post precedente.')
            ->assertDontSee('class="ob-card ob-welcome"', false);
    }
}
