<?php

namespace App\Application\Services;

use App\Domain\Accounts\User;
use App\Federation\Actors\Actor;

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
    /**
     * @param  array{locale: string, default_post_visibility: string, manually_approves_followers?: bool|null, discoverable?: bool|null}  $data
     */
    public function update(User $user, array $data): void
    {
        $manuallyApprovesFollowers = (bool) ($data['manually_approves_followers'] ?? false);

        $user->settings->update([
            'locale' => $data['locale'],
            'default_post_visibility' => $data['default_post_visibility'],
            'manually_approves_followers' => $manuallyApprovesFollowers,
            'discoverable' => (bool) ($data['discoverable'] ?? false),
        ]);

        $user->actor?->update(['manually_approves_followers' => $manuallyApprovesFollowers]);
    }
}
