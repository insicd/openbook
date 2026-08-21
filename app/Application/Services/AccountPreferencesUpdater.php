<?php

namespace App\Application\Services;

use App\Domain\Accounts\User;
use App\Domain\Accounts\UserSetting;
use App\Federation\Delivery\ActivityDelivery;
use App\Federation\Serialization\ActivitySerializer;

/**
 * Applica le preferenze personali dell'account (lingua, visibilita'
 * predefinita dei nuovi post, approvazione manuale dei follower,
 * discoverable/indexable). "manually_approves_followers", "discoverable" e
 * "indexable" sono duplicati di proposito su {@see Actor}: sono i campi
 * del documento Person federato (Mastodon / FEP-5feb).
 */
final class AccountPreferencesUpdater
{
    public function __construct(
        private readonly ActivityDelivery $delivery,
    ) {}

    /**
     * @param  array{locale: string, default_post_visibility: string, manually_approves_followers?: bool|null, discoverable?: bool|null, indexable?: bool|null, direct_message_policy: string}  $data
     */
    public function update(User $user, array $data): void
    {
        $manuallyApprovesFollowers = (bool) ($data['manually_approves_followers'] ?? false);
        $discoverable = (bool) ($data['discoverable'] ?? false);
        $indexable = (bool) ($data['indexable'] ?? false);

        $user->settings->update([
            'locale' => $data['locale'],
            'default_post_visibility' => $data['default_post_visibility'],
            'manually_approves_followers' => $manuallyApprovesFollowers,
            'discoverable' => $discoverable,
            'indexable' => $indexable,
            'direct_message_policy' => $data['direct_message_policy']
                ?? $user->settings->direct_message_policy
                ?? UserSetting::DM_POLICY_EVERYONE,
        ]);

        $actor = $user->actor;

        if ($actor === null) {
            return;
        }

        $actorChanged = $actor->manually_approves_followers !== $manuallyApprovesFollowers
            || (bool) $actor->discoverable !== $discoverable
            || (bool) $actor->indexable !== $indexable;

        $actor->update([
            'manually_approves_followers' => $manuallyApprovesFollowers,
            'discoverable' => $discoverable,
            'indexable' => $indexable,
        ]);

        if ($actorChanged) {
            $actor = $actor->fresh(['key', 'endpoints', 'user.profile', 'user.settings']) ?? $actor;
            $this->delivery->deliverToFollowers($actor, ActivitySerializer::updateActor($actor));
        }
    }
}
