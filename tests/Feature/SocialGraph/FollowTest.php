<?php

namespace Tests\Feature\SocialGraph;

use App\Application\Services\FollowManager;
use App\Domain\Notifications\Notification;
use App\Domain\SocialGraph\Follow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class FollowTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_following_an_open_account_is_immediately_accepted(): void
    {
        $follower = $this->createFullAccount('seguace1');
        $target = $this->createFullAccount('seguito1');

        $follow = app(FollowManager::class)->follow($follower->actor, $target->actor);

        $this->assertSame(Follow::STATUS_ACCEPTED, $follow->status);
        $this->assertNotNull($follow->accepted_at);
        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $target->id,
            'type' => Notification::TYPE_NEW_FOLLOWER,
        ]);
    }

    public function test_following_a_protected_account_requires_approval(): void
    {
        $follower = $this->createFullAccount('seguace2');
        $target = $this->createFullAccount('seguito2');
        $target->actor->update(['manually_approves_followers' => true]);

        $followManager = app(FollowManager::class);
        $follow = $followManager->follow($follower->actor, $target->actor);

        $this->assertSame(Follow::STATUS_PENDING, $follow->status);
        $this->assertFalse($followManager->isFollowing($follower->actor, $target->actor));
        $this->assertTrue($followManager->hasPendingRequest($follower->actor, $target->actor));

        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $target->id,
            'type' => Notification::TYPE_FOLLOW_REQUEST,
        ]);
    }

    public function test_the_target_can_accept_a_pending_follow_request(): void
    {
        $follower = $this->createFullAccount('seguace3');
        $target = $this->createFullAccount('seguito3');
        $target->actor->update(['manually_approves_followers' => true]);

        $followManager = app(FollowManager::class);
        $followManager->follow($follower->actor, $target->actor);
        $followManager->accept($target->actor, $follower->actor);

        $this->assertTrue($followManager->isFollowing($follower->actor, $target->actor));
        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $follower->id,
            'type' => Notification::TYPE_FOLLOW_ACCEPTED,
        ]);
    }

    public function test_the_target_can_reject_a_pending_follow_request(): void
    {
        $follower = $this->createFullAccount('seguace4');
        $target = $this->createFullAccount('seguito4');
        $target->actor->update(['manually_approves_followers' => true]);

        $followManager = app(FollowManager::class);
        $followManager->follow($follower->actor, $target->actor);
        $followManager->reject($target->actor, $follower->actor);

        $this->assertDatabaseCount('follows', 0);
        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $follower->id,
            'type' => Notification::TYPE_FOLLOW_REJECTED,
            'actor_id' => $target->actor->id,
        ]);
    }

    public function test_a_user_cannot_follow_themselves(): void
    {
        $user = $this->createFullAccount('narciso');

        $this->expectException(\InvalidArgumentException::class);
        app(FollowManager::class)->follow($user->actor, $user->actor);
    }

    public function test_unfollowing_removes_the_relation(): void
    {
        $follower = $this->createFullAccount('seguace5');
        $target = $this->createFullAccount('seguito5');

        $followManager = app(FollowManager::class);
        $followManager->follow($follower->actor, $target->actor);
        $followManager->unfollow($follower->actor, $target->actor);

        $this->assertFalse($followManager->isFollowing($follower->actor, $target->actor));
        $this->assertDatabaseCount('follows', 0);
    }

    public function test_mutual_followers_are_detected(): void
    {
        $alice = $this->createFullAccount('mutuo1');
        $bob = $this->createFullAccount('mutuo2');

        $followManager = app(FollowManager::class);
        $followManager->follow($alice->actor, $bob->actor);
        $followManager->follow($bob->actor, $alice->actor);

        $this->assertTrue($followManager->areMutualFollowers($alice->actor, $bob->actor));
    }

    public function test_follow_and_unfollow_work_through_http_routes(): void
    {
        $follower = $this->createFullAccount('httpfollower');
        $target = $this->createFullAccount('httptarget');

        $this->actingAs($follower)->post(route('follow.store', $target))->assertRedirect();
        $this->assertDatabaseHas('follows', [
            'follower_id' => $follower->actor->id,
            'following_id' => $target->actor->id,
        ]);

        $this->actingAs($follower)->delete(route('follow.destroy', $target))->assertRedirect();
        $this->assertDatabaseCount('follows', 0);
    }

    public function test_only_the_target_can_accept_a_follow_request_via_http(): void
    {
        $follower = $this->createFullAccount('httpfollower2');
        $target = $this->createFullAccount('httptarget2');
        $target->actor->update(['manually_approves_followers' => true]);

        app(FollowManager::class)->follow($follower->actor, $target->actor);
        $follow = Follow::query()->firstOrFail();

        $stranger = $this->createFullAccount('httpstranger');
        $this->actingAs($stranger)->post(route('follow.accept', $follow))->assertForbidden();

        $this->actingAs($target)->post(route('follow.accept', $follow))->assertRedirect();
        $this->assertSame(Follow::STATUS_ACCEPTED, $follow->fresh()->status);
    }
}
