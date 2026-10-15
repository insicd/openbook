<?php

namespace App\Federation\Polls;

use App\Application\Services\DomainBlockManager;
use App\Domain\Polls\RemotePollVoteChoice;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;

/** Recheck privacy and moderation when an already committed vote leaves the queue. */
final class PollVoteDeliveryGuard
{
    public function allows(string $choiceId, Actor $voter, string $inbox, DomainBlockManager $blocks): bool
    {
        $choice = RemotePollVoteChoice::query()->with('vote.poll.post.actor')->find($choiceId);
        $post = $choice?->vote?->poll?->post;
        if ($post === null || $choice->vote->actor_id !== $voter->id
            || ! $voter->isLocal() || ! $voter->isActive() || ! $voter->user?->isActive()
            || ! $post->isPublished() || ! $post->actor->isActive() || $post->actor->isRemotelySuspended()
            || $blocks->isBlockedUrl($inbox) || $blocks->isBlockedUrl($post->actor->uri)
            || $blocks->isBlockedUrl($post->uri ?? '')) {
            return false;
        }

        return Post::query()->whereKey($post->id)->visibleTo($voter)->exists();
    }
}
