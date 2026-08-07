<?php

namespace App\Application\Services;

use App\Domain\Accounts\UserSetting;
use App\Federation\Actors\Actor;

/**
 * Verifica se un Actor puo' inviare messaggi diretti a un destinatario locale.
 */
final class DirectMessagePolicy
{
    public function __construct(
        private readonly FollowManager $followManager,
    ) {}

    public function canSend(Actor $sender, Actor $recipient): bool
    {
        if ($sender->id === $recipient->id) {
            return false;
        }

        if (! $recipient->isLocal()) {
            return $sender->isLocal() && $recipient->isPerson() && $recipient->isActive();
        }

        if (! $recipient->isPerson() || ! $recipient->isActive()) {
            return false;
        }

        if (! $sender->isActive()) {
            return false;
        }

        $recipientUser = $recipient->user;

        if ($recipientUser === null) {
            return false;
        }

        $policy = $recipientUser->settings?->direct_message_policy ?? UserSetting::DM_POLICY_EVERYONE;

        return match ($policy) {
            UserSetting::DM_POLICY_NOBODY => false,
            UserSetting::DM_POLICY_FOLLOWERS => $this->followManager->isFollowing($sender, $recipient),
            default => true,
        };
    }
}
