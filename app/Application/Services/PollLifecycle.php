<?php

namespace App\Application\Services;

use App\Domain\Polls\Poll;
use App\Domain\Polls\PollVote;
use App\Domain\Posts\Post;
use Illuminate\Support\Facades\DB;

final class PollLifecycle
{
    public function detachVoter(string $actorId): void
    {
        // The keyed hashes retain replay protection; no raw personal vote URIs are stored.
        PollVote::query()->where('actor_id', $actorId)->update(['actor_id' => null]);
    }

    public function removeFromDeletedPost(Post $post): void
    {
        DB::transaction(function () use ($post): void {
            $poll = Poll::query()->where('post_id', $post->id)->lockForUpdate()->first();
            if ($poll === null) {
                return;
            }
            $poll->votes()->delete();
            $poll->options()->delete();
            // Keep only metadata while the post exists as a Question tombstone.
            $poll->forceFill([
                'voters_count' => 0, 'publication_pending' => false, 'needs_delivery' => false,
                'closed_at' => $poll->closed_at ?? now(),
            ])->save();
            $post->setRelation('poll', $poll);
        });
    }
}
