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

    public function test_the_header_exposes_notification_and_search_panels_instead_of_direct_links(): void
    {
        $user = $this->createFullAccount('headerpanels');

        $response = $this->actingAs($user)->get(route('feed.index'));

        $response->assertOk();
        $response->assertSee('data-header-panel="notifications"', false);
        $response->assertSee('data-header-panel="search"', false);
        $response->assertSee('id="ob-header-search-form"', false);
        $response->assertSee('id="ob-notifications-panel"', false);
        $response->assertSee('assets/js/header-panels.js', false);
        // La campanella della navbar non e' piu' un link diretto alla pagina.
        $response->assertDontSee(
            'href="'.route('notifications.index').'" class="ob-icon-btn"',
            false
        );
        $response->assertDontSee(
            'href="'.route('search.create').'" class="ob-icon-btn"',
            false
        );
    }

    public function test_marking_notifications_read_via_ajax_returns_json(): void
    {
        $follower = $this->createFullAccount('notiffollower3');
        $target = $this->createFullAccount('notiftarget3');

        app(FollowManager::class)->follow($follower->actor, $target->actor);

        $response = $this->actingAs($target)
            ->postJson(route('notifications.read'));

        $response->assertOk();
        $response->assertJson(['ok' => true]);

        $notification = Notification::query()->where('recipient_id', $target->id)->firstOrFail();
        $this->assertNotNull($notification->read_at);
    }

    public function test_the_header_dropdown_lists_a_recent_notification(): void
    {
        $follower = $this->createFullAccount('notiffollower4');
        $target = $this->createFullAccount('notiftarget4');

        app(FollowManager::class)->follow($follower->actor, $target->actor);

        $response = $this->actingAs($target)->get(route('feed.index'));

        $response->assertOk();
        $response->assertSee('notiffollower4', false);
        $response->assertSee(__('openbook.notifications.view_all'), false);
    }
}
