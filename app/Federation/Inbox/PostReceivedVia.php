<?php

namespace App\Federation\Inbox;

use App\Domain\Federation\Relay;
use App\Federation\Actors\Actor;
use App\Federation\Support\ActivityPubUri;

/** Local acquisition context, separate from object authentication and statistics. */
final class PostReceivedVia
{
    public static function transport(?Actor $actor, ?Relay $relay): ?array
    {
        if ($relay !== null) {
            return ['relay' => $relay->actor_uri ?: $relay->inbox_url];
        }

        return $actor?->isGroup() ? ['community' => $actor->uri] : null;
    }

    public static function inbox(?InboxItem $item, ?Actor $announcer = null): ?array
    {
        $via = $item?->received_via ?? self::transport(null, $item?->relay) ?? [];
        if ($announcer?->isGroup()
            && (! isset($via['relay']) || ! ActivityPubUri::same($announcer->uri, $via['relay']))) {
            $via['community'] ??= $announcer->uri;
        }

        return $via ?: null;
    }
}
