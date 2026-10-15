<?php

namespace App\Application\Services;

use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;

/** Account and moderation checks shared by voting and the local-poll UI. */
final class LocalPollInteractionPolicy
{
    public function __construct(private readonly DomainBlockManager $blocks) {}

    public function allows(Actor $voter, Post $post): bool
    {
        return $voter->isActive() && ! $voter->isRemotelySuspended()
            && (! $voter->isLocal() || $voter->user?->isActive())
            && ! $this->blocks->isBlockedUrl($voter->uri)
            && ! $post->isRemote() && $post->conversation_id === null && $post->isPublished()
            && in_array($post->visibility, [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_UNLISTED, Post::VISIBILITY_FOLLOWERS], true)
            && $post->actor?->isLocal() && $post->actor->isActive() && $post->actor->user?->isActive();
    }
}
