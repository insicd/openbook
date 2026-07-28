<?php

namespace Tests\Feature;

use App\Application\Services\FollowManager;
use App\Domain\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_a_guest_cannot_view_notifications(): void
    {
        $this->get(route('notifications.index'))->assertRedirect(route('login'));
    }

    public function test_a_user_sees_only_their_own_notifications(): void
    {
        $follower = $this->createFullAccount('notiffollower');
        $target = $this->createFullAccount('notiftarget');

        app(FollowManager::class)->follow($follower->actor, $target->actor);

        $response = $this->actingAs($target)->get(route('notifications.index'));

        $response->assertOk();
        $response->assertSee('notiffollower');
    }

    public function test_visiting_the_notifications_page_marks_them_as_read(): void
    {
        $follower = $this->createFullAccount('notiffollower2');
        $target = $this->createFullAccount('notiftarget2');

        app(FollowManager::class)->follow($follower->actor, $target->actor);

        $this->assertDatabaseHas('notifications', ['recipient_id' => $target->id, 'read_at' => null]);

        $this->actingAs($target)->get(route('notifications.index'));

        $notification = Notification::query()->where('recipient_id', $target->id)->firstOrFail();
        $this->assertNotNull($notification->read_at);
    }
}
