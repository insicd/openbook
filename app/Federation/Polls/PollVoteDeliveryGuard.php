<?php

namespace App\Federation\Polls;

use App\Application\Services\DomainBlockManager;
use App\Application\Services\RemotePollInteractionPolicy;
use App\Domain\Polls\RemotePollVoteChoice;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;

/** Recheck privacy and moderation when an already committed vote leaves the queue. */
final class PollVoteDeliveryGuard
{
    public function __construct(
        private readonly RemotePollInteractionPolicy $interactionPolicy,
        private readonly DomainBlockManager $blocks,
    ) {}

    public function allows(string $choiceId, Actor $voter, string $inbox): bool
    {
        $choice = RemotePollVoteChoice::query()->with('vote.poll.post.actor')->find($choiceId);
        $post = $choice?->vote?->poll?->post;
        if ($post === null || $choice->vote->actor_id !== $voter->id
            || ! $this->interactionPolicy->allows($voter, $post) || $this->blocks->isBlockedUrl($inbox)) {
            return false;
        }

        return Post::query()->whereKey($post->id)->visibleTo($voter)->exists();
    }
}
