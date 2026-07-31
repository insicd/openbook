<?php

namespace App\Application\Services;

use App\Domain\Comments\Comment;
use App\Domain\Posts\Post;
use Illuminate\Support\Facades\DB;

/**
 * Soft-delete di un commento: svuota il corpo, marca lo stato e aggiorna i
 * contatori denormalizzati (post.comments_count e parent.replies_count).
 * Idempotente se il commento e' gia' eliminato.
 */
final class CommentSoftDeleter
{
    public function delete(Comment $comment): bool
    {
        if ($comment->status === Comment::STATUS_DELETED) {
            return false;
        }

        DB::transaction(function () use ($comment): void {
            $comment->update([
                'body' => '',
                'status' => Comment::STATUS_DELETED,
            ]);

            Post::query()
                ->whereKey($comment->post_id)
                ->where('comments_count', '>', 0)
                ->decrement('comments_count');

            if ($comment->parent_comment_id !== null) {
                Comment::query()
                    ->whereKey($comment->parent_comment_id)
                    ->where('replies_count', '>', 0)
                    ->decrement('replies_count');
            }
        });

        return true;
    }
}
