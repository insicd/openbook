<?php

namespace App\Federation\Serialization;

use App\Domain\Polls\Poll;
use App\Domain\Polls\PollOption;

final class LocalPollSerializer
{
    /** @param array<string, mixed> $note @return array<string, mixed> */
    public static function forPost(array $note, Poll $poll): array
    {
        $poll->loadMissing('options');
        $note['@context'] = ['https://www.w3.org/ns/activitystreams', ['votersCount' => 'http://joinmastodon.org/ns#votersCount']];
        $note['type'] = 'Question';
        $note['endTime'] = $poll->ends_at->toAtomString();
        $note['votersCount'] = $poll->voters_count;
        $note[$poll->multiple ? 'anyOf' : 'oneOf'] = $poll->options->map(static fn (PollOption $option): array => [
            'type' => 'Note', 'name' => $option->name,
            'replies' => ['type' => 'Collection', 'totalItems' => $option->votes_count],
        ])->all();
        $updated = $poll->results_updated_at;
        if ($updated->greaterThan($note['published'])
            && (! isset($note['updated']) || $updated->greaterThan($note['updated']))) {
            $note['updated'] = $updated->toAtomString();
        }
        if ($poll->isClosed()) {
            $note['closed'] = ($poll->closed_at ?? $poll->ends_at)->toAtomString();
        }

        return $note;
    }
}
