<?php

namespace App\Application\Services;

use App\Federation\Actors\Actor;
use Illuminate\Auth\Access\AuthorizationException;

/** Prevent new local interactions with accounts suspended by their own server. */
final class RemoteInteractionGuard
{
    public static function assertAllowed(Actor $initiator, ?Actor ...$targets): void
    {
        if (! $initiator->isLocal()) {
            return;
        }

        foreach ($targets as $target) {
            if ($target?->isRemotelySuspended()) {
                throw new AuthorizationException(__('openbook.profile.remote_suspended_notice'));
            }
        }
    }
}
