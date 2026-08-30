<?php

namespace App\Infrastructure\Push;

enum PushDeliveryStatus: string
{
    case Delivered = 'delivered';
    case Failed = 'failed';
    case InvalidSubscription = 'invalid_subscription';
}
