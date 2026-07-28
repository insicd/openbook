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
        return $post->actor->user_id === $user->id || $user->is_admin;
    }
}
