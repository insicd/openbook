<?php

namespace Tests\Feature;

use App\Domain\Notifications\PushSubscription;
use App\Infrastructure\Database\SystemSetting;
use Base64Url\Base64Url;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class PushSubscriptionTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private const ENDPOINT = 'https://push.example.test/subscriptions/browser-one';

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'endpoint' => self::ENDPOINT,
            'expirationTime' => 1893456000000,
            'keys' => [
                'p256dh' => Base64Url::encode(str_repeat('p', 65)),
                'auth' => Base64Url::encode(str_repeat('a', 16)),
            ],
        ];
    }

    public function test_opening_settings_initializes_and_exposes_one_encrypted_vapid_key_pair(): void
    {
        $user = $this->createFullAccount('alice');

        $first = $this->actingAs($user)->get(route('settings.edit'));
        $keys = json_decode(SystemSetting::get('push_vapid_keys'), true, flags: JSON_THROW_ON_ERROR);

        $first->assertOk()->assertSee($keys['publicKey'], false);
        $this->assertSame(1, DB::table('system_settings')->where('key', 'push_vapid_keys')->count());
        $this->assertStringNotContainsString($keys['privateKey'], DB::table('system_settings')->where('key', 'push_vapid_keys')->value('value'));

        $this->actingAs($user)->get(route('settings.edit'))->assertOk();
        $this->assertSame($keys, json_decode(SystemSetting::get('push_vapid_keys'), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_a_user_can_subscribe_and_sensitive_values_are_encrypted_at_rest(): void
    {
        $user = $this->createFullAccount('alice');

        $this->actingAs($user)
            ->postJson(route('settings.push_subscriptions.store'), $this->payload())
            ->assertOk()
            ->assertJsonPath('endpoint_hash', hash('sha256', self::ENDPOINT));

        $subscription = PushSubscription::query()->sole();
        $this->assertSame($user->id, $subscription->user_id);
        $this->assertSame(self::ENDPOINT, $subscription->endpoint);
        $this->assertSame(1893456000000, $subscription->expiration_time);

        $raw = DB::table('push_subscriptions')->first();
        $this->assertNotSame(self::ENDPOINT, $raw->endpoint);
        $this->assertNotSame($this->payload()['keys']['p256dh'], $raw->public_key);
        $this->assertNotSame($this->payload()['keys']['auth'], $raw->auth_token);
    }

    public function test_subscribing_an_existing_browser_reattaches_it_to_the_current_user(): void
    {
        $alice = $this->createFullAccount('alice');
        $bob = $this->createFullAccount('bob');

        $this->actingAs($alice)->postJson(route('settings.push_subscriptions.store'), $this->payload())->assertOk();
        $this->actingAs($bob)->postJson(route('settings.push_subscriptions.store'), $this->payload())->assertOk();

        $this->assertSame(1, PushSubscription::query()->count());
        $this->assertSame($bob->id, PushSubscription::query()->sole()->user_id);
    }

    public function test_unsubscribe_only_deletes_the_current_users_matching_subscription(): void
    {
        $alice = $this->createFullAccount('alice');
        $bob = $this->createFullAccount('bob');
        $this->actingAs($alice)->postJson(route('settings.push_subscriptions.store'), $this->payload());

        $this->actingAs($bob)
            ->deleteJson(route('settings.push_subscriptions.destroy'), ['endpoint' => self::ENDPOINT])
            ->assertNoContent();
        $this->assertDatabaseCount('push_subscriptions', 1);

        $this->actingAs($alice)
            ->deleteJson(route('settings.push_subscriptions.destroy'), ['endpoint' => self::ENDPOINT])
            ->assertNoContent();
        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_subscription_payload_is_validated(): void
    {
        $user = $this->createFullAccount('alice');

        $this->actingAs($user)->postJson(route('settings.push_subscriptions.store'), [
            'endpoint' => 'http://push.example.test/insecure',
            'keys' => ['p256dh' => '', 'auth' => 'contains spaces'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['endpoint', 'keys.p256dh', 'keys.auth']);
    }

    public function test_guests_cannot_manage_push_subscriptions(): void
    {
        $this->postJson(route('settings.push_subscriptions.store'), $this->payload())->assertUnauthorized();
        $this->deleteJson(route('settings.push_subscriptions.destroy'), ['endpoint' => self::ENDPOINT])->assertUnauthorized();
    }
}
