<?php

namespace App\Application\Services;

use App\Domain\Accounts\User;
use App\Domain\Accounts\UserSetting;
use App\Federation\Actors\Actor;
use App\Federation\Delivery\ActivityDelivery;
use App\Federation\Serialization\ActivitySerializer;

/**
 * Applica le preferenze personali dell'account (lingua, fuso orario
 * implicito, visibilita' predefinita dei nuovi post, approvazione manuale
 * dei follower, presenza nei suggerimenti). "manually_approves_followers" e'
 * duplicato di proposito su {@see Actor}, che resta
 * l'unica colonna letta da {@see FollowManager} per decidere se una
 * richiesta di follow resta in attesa: qui vengono aggiornate entrambe per
 * non lasciarle disallineate.
 */
final class AccountPreferencesUpdater
{
    public function __construct(
        private readonly ActivityDelivery $delivery,
    ) {}

    /**
     * @param  array{locale: string, default_post_visibility: string, manually_approves_followers?: bool|null, discoverable?: bool|null, direct_message_policy: string}  $data
     */
    public function update(User $user, array $data): void
    {
        $manuallyApprovesFollowers = (bool) ($data['manually_approves_followers'] ?? false);

        $user->settings->update([
            'locale' => $data['locale'],
            'default_post_visibility' => $data['default_post_visibility'],
            'manually_approves_followers' => $manuallyApprovesFollowers,
            'discoverable' => (bool) ($data['discoverable'] ?? false),
            'direct_message_policy' => $data['direct_message_policy']
                ?? $user->settings->direct_message_policy
                ?? UserSetting::DM_POLICY_EVERYONE,
        ]);

        $actor = $user->actor;

        // "locale", "default_post_visibility" e "discoverable" sono
        // preferenze puramente locali, assenti dal documento Actor: un
        // "Update" federato serve solo se cambia l'unico campo di questo
        // form che vi compare davvero.
        if ($actor === null) {
            return;
        }

        $manuallyApprovesFollowersChanged = $actor->manually_approves_followers !== $manuallyApprovesFollowers;

        $actor->update(['manually_approves_followers' => $manuallyApprovesFollowers]);

        if ($manuallyApprovesFollowersChanged) {
            $this->delivery->deliverToFollowers($actor, ActivitySerializer::updateActor($actor));
        }
    }
}
