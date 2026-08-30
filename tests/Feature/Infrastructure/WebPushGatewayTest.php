<?php

namespace Tests\Feature\Infrastructure;

use App\Domain\Notifications\PushSubscription;
use App\Infrastructure\Push\PushDeliveryStatus;
use App\Infrastructure\Push\VapidKeyManager;
use App\Infrastructure\Push\WebPushGateway;
use App\Infrastructure\Security\Http\SsrfGuard;
use Base64Url\Base64Url;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Minishlink\WebPush\VAPID;
use Tests\TestCase;

class WebPushGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_classifies_success_expiration_and_transient_http_failures(): void
    {
        $responses = new MockHandler([
            new Response(201),
            new Response(410, [], 'Gone'),
            new Response(503, [], 'Unavailable'),
        ]);
        $gateway = new WebPushGateway(
            app(VapidKeyManager::class),
            app(SsrfGuard::class),
            new Client(['handler' => HandlerStack::create($responses)]),
        );
        $payload = ['title' => 'OpenBook', 'body' => 'A notification'];

        $this->assertSame(PushDeliveryStatus::Delivered, $gateway->send($this->subscription('success'), $payload));
        $this->assertSame(PushDeliveryStatus::InvalidSubscription, $gateway->send($this->subscription('gone'), $payload));
        $this->assertSame(PushDeliveryStatus::Failed, $gateway->send($this->subscription('failure'), $payload));
    }

    public function test_it_rejects_an_unsafe_endpoint_without_contacting_it(): void
    {
        $responses = new MockHandler;
        $gateway = new WebPushGateway(
            app(VapidKeyManager::class),
            app(SsrfGuard::class),
            new Client(['handler' => HandlerStack::create($responses)]),
        );
        $subscription = $this->subscription('unsafe');
        $subscription->endpoint = 'https://127.0.0.1/push';

        $this->assertSame(
            PushDeliveryStatus::InvalidSubscription,
            $gateway->send($subscription, ['title' => 'OpenBook']),
        );
        $this->assertSame(0, $responses->count());
    }

    private function subscription(string $suffix): PushSubscription
    {
        $keys = VAPID::createVapidKeys();

        return (new PushSubscription)->forceFill([
            'id' => '01900000-0000-7000-8000-'.str_pad((string) crc32($suffix), 12, '0', STR_PAD_LEFT),
            'endpoint' => 'https://push.example.test/'.$suffix,
            'public_key' => $keys['publicKey'],
            'auth_token' => Base64Url::encode(random_bytes(16)),
        ]);
    }
}
