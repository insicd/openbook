<?php

namespace App\Application\Services;

use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;

/**
 * Post da citare in un messaggio privato: pubblicato e visibile al mittente.
 * I messaggi diretti non si inoltrano (restano nella conversazione originale).
 */
final class QuotedPostResolver
{
    public function resolveForShare(?Actor $viewer, ?string $quotedPostId): ?Post
    {
        if ($viewer === null || $quotedPostId === null || $quotedPostId === '') {
            return null;
        }

        $post = Post::query()
            ->with(Post::CARD_RELATIONS)
            ->whereKey($quotedPostId)
            ->where('status', Post::STATUS_PUBLISHED)
            ->visibleTo($viewer)
            ->first();

        if ($post === null || $post->isDirectMessage()) {
            return null;
        }

        return $post;
    }
}
