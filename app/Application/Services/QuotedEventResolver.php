<?php

namespace App\Application\Services;

use App\Domain\Events\Event;
use App\Federation\Actors\Actor;

final class QuotedEventResolver
{
    public function resolveForShare(?Actor $viewer, ?string $eventId): ?Event
    {
        if ($viewer === null || $eventId === null || $eventId === '') {
            return null;
        }

        return Event::query()
            ->with(['actor.user.profile', 'location', 'media.thumbnail', 'attributions.user.profile'])
            ->whereKey($eventId)
            ->where('status', '!=', Event::STATUS_DELETED)
            ->visibleTo($viewer)
            ->first();
    }
}
