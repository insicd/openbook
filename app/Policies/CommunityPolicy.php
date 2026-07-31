<?php

namespace App\Policies;

use App\Domain\Accounts\User;
use App\Domain\Communities\Community;

class CommunityPolicy
{
    public function create(User $user): bool
    {
        return $user->isActive();
    }

    public function view(?User $user, Community $community): bool
    {
        if (! $community->is_private) {
            return true;
        }

        if ($user === null || $user->actor === null) {
            return false;
        }

        return $community->isOwnedBy($user) || $community->isMember($user->actor);
    }

    public function update(User $user, Community $community): bool
    {
        return $community->isOwnedBy($user);
    }

    public function join(User $user, Community $community): bool
    {
        return $user->isActive() && $user->actor !== null && ! $community->isOwnedBy($user);
    }

    public function leave(User $user, Community $community): bool
    {
        return $user->actor !== null
            && ! $community->isOwnedBy($user)
            && $community->isMember($user->actor);
    }

    public function moderate(User $user, Community $community): bool
    {
        return $community->isModerator($user) || $user->canModerate();
    }

    public function manageModerators(User $user, Community $community): bool
    {
        return $community->isOwnedBy($user);
    }

    public function post(User $user, Community $community): bool
    {
        return $user->actor !== null && (
            $community->isOwnedBy($user) || $community->isMember($user->actor)
        );
    }
}
