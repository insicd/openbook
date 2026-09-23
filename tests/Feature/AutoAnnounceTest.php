<?php

namespace Tests\Feature;

use App\Application\Services\CommunityRegistrar;
use App\Application\Services\FollowManager;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class AutoAnnounceTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_a_follower_can_enable_and_disable_auto_announce_on_a_remote_actor(): void
    {
        $viewer = $this->createFullAccount('autosharer');
        $remote = $this->createRemoteActor('blogger');
        Follow::query()->create([
            'follower_id' => $viewer->actor->id,
            'following_id' => $remote->id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);

        $this->actingAs($viewer)
            ->from(route('actors.show', $remote))
            ->post(route('actors.auto_announce', $remote))
            ->assertRedirect(route('actors.show', $remote))
            ->assertSessionHas('status', __('openbook.follow.auto_announce_enabled'));

        $this->assertTrue(app(FollowManager::class)->autoAnnounces($viewer->actor, $remote));

        $this->actingAs($viewer)
            ->from(route('actors.show', $remote))
            ->delete(route('actors.auto_announce.destroy', $remote))
            ->assertRedirect(route('actors.show', $remote));

        $this->assertFalse(app(FollowManager::class)->autoAnnounces($viewer->actor, $remote));
    }

    public function test_auto_announce_requires_an_accepted_follow(): void
    {
        $viewer = $this->createFullAccount('nofollowshare');
        $remote = $this->createRemoteActor('sconosciuto');

        $this->actingAs($viewer)
            ->from(route('actors.show', $remote))
            ->post(route('actors.auto_announce', $remote))
            ->assertSessionHasErrors('auto_announce');
    }

    public function test_a_user_cannot_auto_announce_themselves(): void
    {
        $viewer = $this->createFullAccount('mestesso');

        $this->actingAs($viewer)
            ->post(route('actors.auto_announce', $viewer->actor))
            ->assertSessionHasErrors('auto_announce');
    }

    public function test_the_profile_menu_appears_when_following(): void
    {
        $author = $this->createFullAccount('autorelokale');
        $viewer = $this->createFullAccount('lettoremenu');
        app(FollowManager::class)->follow($viewer->actor, $author->actor);

        $this->actingAs($viewer)
            ->get(route('profile.show', $author->username))
            ->assertOk()
            ->assertSee(__('openbook.follow.auto_announce_enable'), false)
            ->assertSee(route('actors.auto_announce', $author->actor), false);
    }

    public function test_cron_shares_only_new_public_posts_without_notifying_the_author(): void
    {
        $author = $this->createFullAccount('autoreauto');
        $sharer = $this->createFullAccount('condivisoreauto');
        app(FollowManager::class)->follow($sharer->actor, $author->actor);

        $old = Post::query()->create([
            'actor_id' => $author->actor->id,
            'body' => 'Post precedente.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->subHour(),
        ]);
        $old->forceFill([
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ])->saveQuietly();

        app(FollowManager::class)->setAutoAnnounce($sharer->actor, $author->actor, true);

        $fresh = Post::query()->create([
            'actor_id' => $author->actor->id,
            'body' => 'Post nuovo da condividere.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        Post::query()->create([
            'actor_id' => $author->actor->id,
            'body' => 'Messaggio privato.',
            'visibility' => Post::VISIBILITY_DIRECT,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $this->artisan('openbook:auto-announce', ['--limit' => 10, '--per-follow' => 10, '--max-time' => 10])
            ->assertSuccessful();

        $this->assertTrue(app(\App\Application\Services\AnnounceManager::class)->hasDirectAnnounced($sharer->actor, $fresh));
        $this->assertFalse(app(\App\Application\Services\AnnounceManager::class)->hasDirectAnnounced($sharer->actor, $old));
        $this->assertSame(0, Notification::query()->where('type', Notification::TYPE_SHARE)->count());
    }

    public function test_cron_shares_new_feed_items_and_delivers_to_remote_followers(): void
    {
        Queue::fake();
        Http::fake([
            'https://blog.example/feed.xml' => Http::response(<<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>Blog</title>
  <entry>
    <id>https://blog.example/old</id>
    <title>Vecchia</title>
    <link href="https://blog.example/old" rel="alternate"/>
    <published>2024-01-01T10:00:00Z</published>
    <summary>Gia importata</summary>
  </entry>
</feed>
XML, 200, ['Content-Type' => 'application/atom+xml']),
        ]);

        $sharer = $this->createFullAccount('feedauto');
        $remoteFollower = $this->createRemoteActor('fanremoto');
        Follow::query()->create([
            'follower_id' => $remoteFollower->id,
            'following_id' => $sharer->actor->id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);

        $this->actingAs($sharer)->get(route('search.create', ['q' => 'https://blog.example/feed.xml']));
        $feed = \App\Federation\Actors\Actor::query()
            ->where('type', \App\Federation\Actors\Actor::TYPE_FEED)
            ->firstOrFail();
        app(FollowManager::class)->follow($sharer->actor, $feed);
        app(FollowManager::class)->setAutoAnnounce($sharer->actor, $feed, true);

        $item = Post::query()->create([
            'actor_id' => $feed->id,
            'source_uri' => 'https://blog.example/new',
            'title' => 'Nuova voce',
            'body' => 'Articolo fresco dal feed.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->subDays(2),
        ]);

        $this->artisan('openbook:auto-announce')->assertSuccessful();

        $this->assertTrue(app(\App\Application\Services\AnnounceManager::class)->hasDirectAnnounced($sharer->actor, $item));
        Queue::assertPushed(
            DeliverActivityJob::class,
            fn (DeliverActivityJob $job): bool => $job->inboxUrl === $remoteFollower->endpoints->shared_inbox
                && ($job->activity['type'] ?? null) === 'Announce',
        );
    }

    public function test_a_private_community_cannot_be_auto_announced(): void
    {
        $owner = $this->createFullAccount('ownerprivata');
        $member = $this->createFullAccount('memberprivata');
        $community = app(CommunityRegistrar::class)->register($owner, [
            'slug' => 'privata',
            'name' => 'Privata',
            'is_private' => true,
        ]);

        Follow::query()->create([
            'follower_id' => $member->actor->id,
            'following_id' => $community->actor->id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        app(FollowManager::class)->setAutoAnnounce($member->actor, $community->actor, true);
    }

    public function test_unfollowing_clears_auto_announce(): void
    {
        $author = $this->createFullAccount('autoresmetti');
        $sharer = $this->createFullAccount('smettiauto');
        app(FollowManager::class)->follow($sharer->actor, $author->actor);
        app(FollowManager::class)->setAutoAnnounce($sharer->actor, $author->actor, true);
        app(FollowManager::class)->unfollow($sharer->actor, $author->actor);

        $this->assertFalse(app(FollowManager::class)->autoAnnounces($sharer->actor, $author->actor));
    }
}
