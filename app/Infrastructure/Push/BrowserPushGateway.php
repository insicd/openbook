<?php

namespace App\Infrastructure\Push;

use App\Domain\Notifications\PushSubscription;

interface BrowserPushGateway
{
    /** @param array<string, mixed> $payload */
    public function send(PushSubscription $subscription, array $payload): PushDeliveryStatus;
}
