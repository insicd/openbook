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
        // Stessa regola dei post: i commenti remoti sono cache locale di
        // Note altrui e non si eliminano da Openbook. Solo l'autore di un
        // commento locale (o un amministratore) puo' cancellarlo.
        if ($comment->isRemote()) {
            return false;
        }

        return $comment->actor->user_id === $user->id || $user->is_admin;
    }
}
