<?php

namespace Tests\Feature;

use App\Application\Services\FollowManager;
use App\Domain\Accounts\User;
use App\Domain\Notifications\Notification;
use App\Domain\Notifications\PushSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class PushNotificationOutboxTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Questi test devono osservare realmente le callback afterCommit.
        DB::commit();
    }

    public function test_a_notification_is_enqueued_after_commit_when_the_recipient_has_a_subscription(): void
    {
        $follower = $this->createFullAccount('pushfollower');
        $target = $this->createFullAccount('pushtarget');
        $this->subscribe($target);

        app(FollowManager::class)->follow($follower->actor, $target->actor);

        $notification = Notification::query()->where('recipient_id', $target->id)->sole();
        $this->assertDatabaseHas('push_notifications', ['notification_id' => $notification->id]);
    }

    public function test_a_notification_is_not_enqueued_without_a_subscription(): void
    {
        $follower = $this->createFullAccount('pushfollower');
        $target = $this->createFullAccount('pushtarget');

        app(FollowManager::class)->follow($follower->actor, $target->actor);

        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('push_notifications', 0);
    }

    public function test_an_outbox_failure_does_not_rollback_the_local_notification(): void
    {
        Log::spy();
        $follower = $this->createFullAccount('pushfollower');
        $target = $this->createFullAccount('pushtarget');
        $this->subscribe($target);
        Schema::drop('push_notifications');

        app(FollowManager::class)->follow($follower->actor, $target->actor);

        $this->assertDatabaseHas('notifications', ['recipient_id' => $target->id]);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_the_live_feed_consumes_all_pending_pushes_for_the_current_user(): void
    {
        $followerOne = $this->createFullAccount('pushfollowerone');
        $followerTwo = $this->createFullAccount('pushfollowertwo');
        $target = $this->createFullAccount('pushtarget');
        $otherTarget = $this->createFullAccount('otherpushtarget');
        $this->subscribe($target);
        $this->subscribe($otherTarget, 'other');

        app(FollowManager::class)->follow($followerOne->actor, $target->actor);
        app(FollowManager::class)->follow($followerTwo->actor, $target->actor);
        app(FollowManager::class)->follow($followerOne->actor, $otherTarget->actor);

        $this->assertDatabaseCount('push_notifications', 3);

        $this->actingAs($target)->getJson(route('notifications.feed'))->assertOk();

        $this->assertSame(0, $this->pendingCountFor($target));
        $this->assertSame(1, $this->pendingCountFor($otherTarget));
    }

    public function test_a_not_modified_live_feed_also_consumes_pending_pushes(): void
    {
        $follower = $this->createFullAccount('pushfollower');
        $target = $this->createFullAccount('pushtarget');
        $this->subscribe($target);
        app(FollowManager::class)->follow($follower->actor, $target->actor);

        $revision = (int) $target->fresh()->notifications_revision;
        $this->actingAs($target)
            ->withHeaders(['If-None-Match' => '"'.$revision.'"'])
            ->get(route('notifications.feed'))
            ->assertStatus(304);

        $this->assertDatabaseCount('push_notifications', 0);
    }

    public function test_notification_messages_can_be_rendered_in_an_explicit_locale(): void
    {
        $follower = $this->createFullAccount('pushfollower');
        $target = $this->createFullAccount('pushtarget');
        app(FollowManager::class)->follow($follower->actor, $target->actor);
        $notification = Notification::query()->where('recipient_id', $target->id)->sole();

        $this->assertSame('pushfollower started following you.', $notification->message('en'));
        $this->assertSame('pushfollower ha iniziato a seguirti.', $notification->message('it'));
    }

    private function subscribe(User $user, string $suffix = 'main'): void
    {
        $endpoint = 'https://push.example.test/'.$suffix;

        PushSubscription::query()->create([
            'user_id' => $user->id,
            'endpoint_hash' => hash('sha256', $endpoint),
            'endpoint' => $endpoint,
            'public_key' => 'public-key-'.$suffix,
            'auth_token' => 'auth-token-'.$suffix,
        ]);
    }

    private function pendingCountFor(User $user): int
    {
        return (int) Notification::query()
            ->where('recipient_id', $user->id)
            ->whereHas('pushNotification')
            ->count();
    }
}
