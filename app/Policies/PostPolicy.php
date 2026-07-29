<?php

namespace App\Policies;

use App\Domain\Accounts\User;
use App\Domain\Posts\Post;

class PostPolicy
{
    public function update(User $user, Post $post): bool
    {
        return $post->actor->user_id === $user->id;
    }

    public function delete(User $user, Post $post): bool
    {
        // I post remoti sono una cache locale di Note altrui: non si
        // "eliminano" da Openbook (andrebbero eventualmente solo
        // invalidati dalla federazione). Solo l'autore di un post locale,
        // o un amministratore, puo' cancellarlo.
        if ($post->isRemote()) {
            return false;
        }

        return $post->actor->user_id === $user->id || $user->is_admin;
    }
}
