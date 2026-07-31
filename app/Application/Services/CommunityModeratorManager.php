<?php

namespace App\Application\Services;

use App\Domain\Accounts\User;
use App\Domain\Communities\Community;
use Illuminate\Validation\ValidationException;

/**
 * Gestisce i moderatori delegati di una community locale (oltre al
 * proprietario). Non e' federato: vale solo su questa istanza.
 */
final class CommunityModeratorManager
{
    public function add(Community $community, string $username): User
    {
        $user = User::query()
            ->where('username', mb_strtolower($username))
            ->where('status', User::STATUS_ACTIVE)
            ->first();

        if ($user === null) {
            throw ValidationException::withMessages([
                'username' => __('openbook.communities.errors.moderator_not_found'),
            ]);
        }

        if ($community->isOwnedBy($user)) {
            throw ValidationException::withMessages([
                'username' => __('openbook.communities.errors.owner_is_already_mod'),
            ]);
        }

        $community->moderators()->syncWithoutDetaching([$user->id]);

        return $user;
    }

    public function remove(Community $community, User $user): void
    {
        if ($community->isOwnedBy($user)) {
            throw ValidationException::withMessages([
                'username' => __('openbook.communities.errors.cannot_remove_owner'),
            ]);
        }

        $community->moderators()->detach($user->id);
    }
}
