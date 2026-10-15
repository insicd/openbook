<?php

namespace App\Application\Services;

use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;

/** Account and moderation rules; callers also check visibility and new-vote expiry. */
final class RemotePollInteractionPolicy
{
    public function __construct(private readonly DomainBlockManager $blocks) {}

    public function allows(Actor $voter, Post $post): bool
    {
        return $voter->isLocal() && $voter->isActive() && $voter->user?->isActive()
            && $post->isRemote() && $post->isPublished() && $post->actor?->isActive()
            && ! $post->actor->isRemotelySuspended()
            && ! $this->blocks->isBlockedUrl($post->uri ?? '')
            && ! $this->blocks->isBlockedUrl($post->actor->uri);
    }
}
