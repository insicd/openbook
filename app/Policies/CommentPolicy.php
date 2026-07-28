<?php

namespace App\Policies;

use App\Domain\Accounts\User;
use App\Domain\Comments\Comment;

class CommentPolicy
{
    public function update(User $user, Comment $comment): bool
    {
        return $comment->actor->user_id === $user->id;
    }

    public function delete(User $user, Comment $comment): bool
    {
        return $comment->actor->user_id === $user->id || $user->is_admin;
    }
}
