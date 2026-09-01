<?php

namespace Tests\Feature\Console;

use App\Application\Services\FollowManager;
use App\Domain\Accounts\User;
use App\Domain\Notifications\Notification;
use App\Domain\Notifications\PushNotification;
use App\Domain\Notifications\PushSubscription;
use App\Infrastructure\Push\BrowserPushGateway;
use App\Infrastructure\Push\PushDeliveryStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class DeliverPushNotificationsCommandTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_it_claims_a_stale_notification_and_delivers_the_localized_payload_to_every_device(): void
    {
        config([
            'app.name' => 'OpenBook Test',
            'openbook.push.grace_period_seconds' => 75,
        ]);
        $follower = $this->createFullAccount('pushsender');
        $target = $this->createFullAccount('pushrecipient');
        $target->settings()->update(['locale' => 'en']);
        $notification = $this->followNotification($follower, $target);
        $outbox = $this->outbox($notification, now()->subSeconds(76));
        $first = $this->subscribe($target, 'first');
        $second = $this->subscribe($target, 'second');
        $expired = $this->subscribe($target, 'expired', 1);

        $gateway = new FakeBrowserPushGateway([
            $first->id => PushDeliveryStatus::Delivered,
            $second->id => PushDeliveryStatus::InvalidSubscription,
        ]);
        $this->app->instance(BrowserPushGateway::class, $gateway);

        $this->artisan('openbook:deliver-push')->assertSuccessful();

        $this->assertDatabaseMissing('push_notifications', ['id' => $outbox->id]);
        $this->assertDatabaseHas('push_subscriptions', ['id' => $first->id]);
        $this->assertDatabaseMissing('push_subscriptions', ['id' => $second->id]);
        $this->assertDatabaseMissing('push_subscriptions', ['id' => $expired->id]);
        $this->assertCount(2, $gateway->deliveries);
        $this->assertSame('OpenBook Test', $gateway->deliveries[0]['payload']['title']);
        $this->assertSame('pushsender started following you.', $gateway->deliveries[0]['payload']['body']);
        $this->assertSame(route('notifications.index'), $gateway->deliveries[0]['payload']['url']);
        $this->assertSame('notification-'.$notification->id, $gateway->deliveries[0]['payload']['tag']);
        $this->assertArrayNotHasKey('icon', $gateway->deliveries[0]['payload']);
    }

    public function test_it_ignores_notifications_inside_the_grace_period(): void
    {
        config(['openbook.push.grace_period_seconds' => 75]);
        $follower = $this->createFullAccount('pushsender');
        $target = $this->createFullAccount('pushrecipient');
        $notification = $this->followNotification($follower, $target);
        $outbox = $this->outbox($notification, now()->subSeconds(74));
        $this->subscribe($target, 'first');
        $gateway = new FakeBrowserPushGateway;
        $this->app->instance(BrowserPushGateway::class, $gateway);

        $this->artisan('openbook:deliver-push')->assertSuccessful();

        $this->assertDatabaseHas('push_notifications', ['id' => $outbox->id]);
        $this->assertCount(0, $gateway->deliveries);
    }

    public function test_it_consumes_read_notifications_without_delivering_them(): void
    {
        $follower = $this->createFullAccount('pushsender');
        $target = $this->createFullAccount('pushrecipient');
        $notification = $this->followNotification($follower, $target);
        $notification->forceFill(['read_at' => now()])->save();
        $outbox = $this->outbox($notification, now()->subMinutes(2));
        $this->subscribe($target, 'first');
        $gateway = new FakeBrowserPushGateway;
        $this->app->instance(BrowserPushGateway::class, $gateway);

        $this->artisan('openbook:deliver-push')->assertSuccessful();

        $this->assertDatabaseMissing('push_notifications', ['id' => $outbox->id]);
        $this->assertCount(0, $gateway->deliveries);
    }

    public function test_a_transient_delivery_failure_is_not_retried_by_openbook(): void
    {
        $follower = $this->createFullAccount('pushsender');
        $target = $this->createFullAccount('pushrecipient');
        $notification = $this->followNotification($follower, $target);
        $outbox = $this->outbox($notification, now()->subMinutes(2));
        $subscription = $this->subscribe($target, 'first');
        $gateway = new FakeBrowserPushGateway([$subscription->id => PushDeliveryStatus::Failed]);
        $this->app->instance(BrowserPushGateway::class, $gateway);

        $this->artisan('openbook:deliver-push')->assertSuccessful();

        $this->assertDatabaseMissing('push_notifications', ['id' => $outbox->id]);
        $this->assertDatabaseHas('push_subscriptions', ['id' => $subscription->id]);
        $this->assertCount(1, $gateway->deliveries);
    }

    public function test_the_service_worker_handles_push_and_notification_click_events(): void
    {
        $worker = file_get_contents(public_path('service-worker.js'));

        $this->assertStringContainsString("addEventListener('push'", $worker);
        $this->assertStringContainsString('showNotification', $worker);
        $this->assertStringContainsString("addEventListener('notificationclick'", $worker);
        $this->assertStringContainsString('clients.openWindow', $worker);
    }

    private function followNotification(User $follower, User $target): Notification
    {
        app(FollowManager::class)->follow($follower->actor, $target->actor);

        return Notification::query()->where('recipient_id', $target->id)->sole();
    }

    private function outbox(Notification $notification, \DateTimeInterface $createdAt): PushNotification
    {
        $outbox = PushNotification::query()->create(['notification_id' => $notification->id]);
        $outbox->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

        return $outbox;
    }

    private function subscribe(User $user, string $suffix, ?int $expirationTime = null): PushSubscription
    {
        $endpoint = 'https://push.example.test/'.$suffix;

        return PushSubscription::query()->create([
            'user_id' => $user->id,
            'endpoint_hash' => hash('sha256', $endpoint),
            'endpoint' => $endpoint,
            'public_key' => 'public-key-'.$suffix,
            'auth_token' => 'auth-token-'.$suffix,
            'expiration_time' => $expirationTime,
        ]);
    }
}

final class FakeBrowserPushGateway implements BrowserPushGateway
{
    /** @var list<array{subscription_id: string, payload: array<string, mixed>}> */
    public array $deliveries = [];

    /** @param array<string, PushDeliveryStatus> $statuses */
    public function __construct(private readonly array $statuses = []) {}

    public function send(PushSubscription $subscription, array $payload): PushDeliveryStatus
    {
        $this->deliveries[] = ['subscription_id' => $subscription->id, 'payload' => $payload];

        return $this->statuses[$subscription->id] ?? PushDeliveryStatus::Delivered;
    }
}
