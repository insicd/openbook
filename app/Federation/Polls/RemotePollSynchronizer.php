<?php

namespace App\Federation\Polls;

use App\Domain\Polls\Poll;
use App\Domain\Posts\Post;
use App\Federation\Inbox\InboxItem;
use Illuminate\Support\Carbon;

/** Called with the post and poll locked inside the shared import transaction. */
final class RemotePollSynchronizer
{
    public function accepts(Poll $poll, RemotePollDocument $document, ?InboxItem $source): bool
    {
        if ($document->updatedAt !== null && $poll->remote_updated_at !== null
            && ! $document->updatedAt->equalTo($poll->remote_updated_at)) {
            return $document->updatedAt->greaterThan($poll->remote_updated_at);
        }

        $observed = $this->resultsReceivedAt($source);
        if ($poll->results_received_at !== null) {
            if ($observed->lessThan($poll->results_received_at)) {
                return false;
            }
            if ($observed->equalTo($poll->results_received_at) && $source !== null) {
                return $poll->source_item_id !== null && strcmp($source->id, $poll->source_item_id) > 0;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $raw */
    public function sync(Post $post, ?Poll $poll, RemotePollDocument $document, array $raw, ?InboxItem $source): void
    {
        $poll ??= new Poll(['post_id' => $post->id]);
        $poll->setRelation('post', $post);
        $poll->fill([
            'multiple' => $document->multiple,
            'ends_at' => array_key_exists('endTime', $raw) ? $document->endsAt : $poll->ends_at,
            'closed' => $poll->isClosed() || $document->closed,
            'closed_at' => $document->closedAt ?? $poll->closed_at,
            'voters_count' => $document->votersCount,
        ])->forceFill([
            'remote_updated_at' => $document->updatedAt ?? $poll->remote_updated_at,
            'results_received_at' => $this->resultsReceivedAt($source),
            'source_item_id' => $source?->id,
        ])->save();

        $existing = $poll->options()->lockForUpdate()->get()->keyBy('name_hash');
        foreach ($existing as $option) {
            $option->setRelation('poll', $poll);
        }
        // Temporary positions avoid unique collisions during swaps and removals.
        foreach ($existing->values() as $offset => $option) {
            $option->forceFill(['position' => RemotePollDocument::MAX_OPTIONS + $offset])->save();
        }
        $kept = [];
        foreach ($document->options as $position => $data) {
            $hash = hash('sha256', $data['name']);
            $option = $existing->get($hash) ?? $poll->options()->make();
            $option->fill(['position' => $position, 'name' => $data['name'], 'votes_count' => $data['votes_count']])->save();
            $kept[] = $option->id;
        }
        $poll->options()->whereNotIn('id', $kept)->reorder()->delete();
    }

    private function resultsReceivedAt(?InboxItem $source): Carbon
    {
        return $source?->received_at ?? now();
    }
}
