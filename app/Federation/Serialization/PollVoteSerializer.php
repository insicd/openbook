<?php

namespace App\Federation\Serialization;

use App\Domain\Polls\RemotePollVoteChoice;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;

final class PollVoteSerializer
{
    /** @return array<string, mixed> */
    public static function create(RemotePollVoteChoice $choice, Actor $voter, Post $post): array
    {
        $objectId = $voter->uri.'#votes/'.$choice->id;
        $audience = [$post->actor->uri];

        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $objectId.'/activity',
            'type' => 'Create',
            'actor' => $voter->uri,
            'to' => $audience,
            'object' => [
                'id' => $objectId,
                'type' => 'Note',
                'name' => $choice->name,
                'attributedTo' => $voter->uri,
                'inReplyTo' => $post->uri,
                'to' => $audience,
            ],
        ];
    }
}
