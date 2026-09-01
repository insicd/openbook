<?php

namespace App\Infrastructure\Push;

use App\Domain\Notifications\PushSubscription;
use App\Infrastructure\Security\Http\SsrfGuard;
use App\Infrastructure\Security\Http\SsrfViolationException;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;
use Throwable;

final class WebPushGateway implements BrowserPushGateway
{
    private ?WebPush $client = null;

    public function __construct(
        private readonly VapidKeyManager $vapidKeys,
        private readonly SsrfGuard $ssrfGuard,
        private readonly ?ClientInterface $httpClient = null,
    ) {}

    public function send(PushSubscription $subscription, array $payload): PushDeliveryStatus
    {
        try {
            $this->ssrfGuard->assertUrlIsSafe($subscription->endpoint);
        } catch (SsrfViolationException $exception) {
            Log::warning('push.delivery_rejected', [
                'subscription_id' => $subscription->id,
                'reason' => $exception->getMessage(),
            ]);

            return PushDeliveryStatus::InvalidSubscription;
        }

        try {
            $webSubscription = new Subscription(
                $subscription->endpoint,
                $subscription->public_key,
                $subscription->auth_token,
                ContentEncoding::aes128gcm,
            );

            $report = $this->webPush()->sendOneNotification(
                $webSubscription,
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            );

            if ($report->isSuccess()) {
                return PushDeliveryStatus::Delivered;
            }

            Log::warning('push.delivery_failed', [
                'subscription_id' => $subscription->id,
                'reason' => $report->getReason(),
            ]);

            return $report->isSubscriptionExpired()
                ? PushDeliveryStatus::InvalidSubscription
                : PushDeliveryStatus::Failed;
        } catch (Throwable $exception) {
            Log::warning('push.delivery_failed', [
                'subscription_id' => $subscription->id,
                'exception' => $exception,
            ]);

            return PushDeliveryStatus::Failed;
        }
    }

    private function webPush(): WebPush
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $keys = $this->vapidKeys->getOrCreate();
        $timeout = max(1, (int) config('openbook.push.http_timeout_seconds', 5));

        $this->client = new WebPush(
            ['VAPID' => [
                'subject' => (string) config('app.url'),
                'publicKey' => $keys['publicKey'],
                'privateKey' => $keys['privateKey'],
            ]],
            [
                'TTL' => max(0, (int) config('openbook.push.ttl_seconds', 3600)),
                'urgency' => 'normal',
            ],
            $this->httpClient ?? new Client([
                'timeout' => $timeout,
                'connect_timeout' => $timeout,
                'allow_redirects' => false,
            ]),
        );

        return $this->client;
    }
}
