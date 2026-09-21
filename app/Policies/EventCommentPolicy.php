<?php

namespace App\Policies;

use App\Domain\Accounts\User;
use App\Domain\Events\EventComment;

class EventCommentPolicy
{
    public function delete(User $user, EventComment $comment): bool
    {
        return ! $comment->isRemote()
            && $comment->isPublished()
            && ($comment->actor?->user_id === $user->id || $user->is_admin);
    }
}
