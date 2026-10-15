<?php

namespace App\Application\Services;

use App\Domain\Polls\Poll;
use App\Domain\Polls\PollDistribution;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Federation\Delivery\ActivityDelivery;
use App\Federation\Polls\PollResultRecipients;
use App\Federation\Serialization\ActivitySerializer;
use App\Federation\Serialization\RelayActivitySerializer;
use Illuminate\Support\Facades\DB;

final class PollResultsProcessor
{
    public function __construct(private readonly ActivityDelivery $delivery, private readonly PollResultRecipients $recipients, private readonly DomainBlockManager $blocks) {}

    public function close(string $pollId): bool
    {
        return DB::transaction(function () use ($pollId): bool {
            $poll = $this->lockedPoll($pollId);
            if ($poll === null || $poll->closed_at !== null || $poll->ends_at === null || $poll->ends_at->isFuture()) {
                return false;
            }
            $poll->forceFill(['closed' => true, 'closed_at' => $poll->ends_at, 'results_updated_at' => now(),
                'revision' => $poll->revision + 1, 'needs_delivery' => true])->save();

            return true;
        }, 3);
    }

    /** A batch and its cursor commit together, so retry never loses part of a fan-out. */
    public function distribute(string $pollId, int $batchSize): int
    {
        return DB::transaction(function () use ($pollId, $batchSize): int {
            $poll = $this->lockedPoll($pollId);
            if ($poll === null || ! $poll->needs_delivery) {
                return 0;
            }
            $post = $poll->post;
            $author = $post->actor;
            if (! $author?->isActive() || ! $author->user?->isActive()) {
                return 0;
            }
            $distribution = PollDistribution::query()->where('poll_id', $poll->id)->lockForUpdate()->first();
            if ($distribution === null) {
                $poll->setRelation('options', $poll->options()->lockForUpdate()->get());
                $post->setRelation('poll', $poll);
                $activity = $poll->publication_pending ? ActivitySerializer::create($post) : ActivitySerializer::update($post);
                if (! $poll->publication_pending) {
                    $activity['id'] = $activity['object']['id'].'/poll-updates/'.$poll->revision;
                }
                // Read the stored representation: MySQL JSON may reorder object keys.
                $distribution = PollDistribution::create(['poll_id' => $poll->id, 'revision' => $poll->revision, 'activity' => $activity])->refresh();
            }
            $activities = $this->activities($distribution);
            $count = 0;
            if ($distribution->phase === 'actors') {
                $query = $this->recipients->query($post, $poll);
                if ($distribution->cursor !== null) {
                    $query->where('destination', '>', $distribution->cursor);
                }
                $destinations = $query->orderBy('destination')->limit($batchSize)->get();
                foreach ($destinations as $destination) {
                    $inbox = $destination->recipient_actor_id === null ? $destination->destination : '';
                    if (! $this->blocks->isBlockedUrl($inbox)) {
                        foreach ($activities as $activity) {
                            $this->delivery->enqueueDirectInTransaction($author, $inbox, $activity,
                                pollRecipientActorId: $destination->recipient_actor_id, pollPostId: $post->id);
                            $count++;
                        }
                    }
                    $distribution->cursor = $destination->destination;
                }
                if ($destinations->count() === $batchSize) {
                    $distribution->save();

                    return $count;
                }
                $distribution->forceFill(['phase' => 'relays', 'cursor' => null]);
            }
            $destinations = $this->delivery->contentRelayDestinations($post)
                ->filter(fn (array $destination): bool => $distribution->cursor === null || strcmp($destination['inbox_url'], $distribution->cursor) > 0)
                ->sortBy('inbox_url')->take($batchSize);
            foreach ($destinations as $destination) {
                $inbox = $destination['inbox_url'];
                if (! $this->blocks->isBlockedUrl($inbox) && ! $this->recipients->query($post, $poll)->where('destination', $inbox)->exists()) {
                    $signer = Actor::query()->findOrFail($destination['actor_id']);
                    foreach ($activities as $activity) {
                        $payload = $signer->id === $author->id ? $activity : RelayActivitySerializer::announceObject(
                            $signer, $activity['object']['id'], $activity['type'] === 'Create' ? $activity['object']['id'] : $activity['id'], $activity['published'],
                        );
                        $this->delivery->enqueueDirectInTransaction($signer, $inbox, $payload,
                            relayId: $destination['relay_id'], pollPostId: $post->id);
                        $count++;
                    }
                }
                $distribution->cursor = $inbox;
            }
            if ($destinations->count() === $batchSize) {
                $distribution->save();
            } else {
                $poll->forceFill(['distributed_revision' => $distribution->revision, 'publication_pending' => false,
                    'needs_delivery' => $poll->revision > $distribution->revision])->save();
                $distribution->delete();
            }

            return $count;
        }, 3);
    }

    /** @return list<array<string, mixed>> */
    private function activities(PollDistribution $distribution): array
    {
        $activity = $distribution->activity;
        if ($activity['type'] !== 'Create' || $distribution->revision === 1) {
            return [$activity];
        }
        // A pre-cron Create may already have reached the peer; its duplicate is ignored.
        // Also publish the coalesced revision as Update so those peers receive new results.
        $update = $activity;
        $update['type'] = 'Update';
        $update['id'] = $activity['object']['id'].'/poll-updates/'.$distribution->revision;
        $update['published'] = $activity['object']['updated'] ?? $activity['published'];

        return [$activity, $update];
    }

    private function lockedPoll(string $pollId): ?Poll
    {
        $postId = Poll::query()->whereKey($pollId)->value('post_id');
        $post = Post::query()->whereKey($postId)->whereNull('uri')->where('status', Post::STATUS_PUBLISHED)->lockForUpdate()->first();
        if ($post === null) {
            return null;
        }
        $poll = Poll::query()->whereKey($pollId)->lockForUpdate()->first();
        $poll?->setRelation('post', $post);

        return $poll;
    }
}
