<?php

namespace App\Domain\Feeds;

use App\Federation\Actors\Actor;

/**
 * Identificatori ActivityPub per un contatto RSS/Atom: restano su questa
 * istanza (i feed non hanno inbox sul sito di origine) cosi' una Note e un
 * Announce possono essere recuperati dalle altre implementazioni.
 */
final class FeedActorUrls
{
    /**
     * @return array{uri: string, profile: string, inbox: string, outbox: string, followers: string, following: string, shared_inbox: string}
     */
    public static function for(Actor $actor): array
    {
        return self::forId($actor->id);
    }

    /**
     * @return array{uri: string, profile: string, inbox: string, outbox: string, followers: string, following: string, shared_inbox: string}
     */
    public static function forId(string $actorId): array
    {
        $base = url('/feeds/'.$actorId);

        return [
            'uri' => $base,
            'profile' => url('/attori/'.$actorId),
            'inbox' => url('/inbox'),
            'outbox' => $base.'/outbox',
            'followers' => $base.'/followers',
            'following' => $base.'/following',
            'shared_inbox' => url('/inbox'),
        ];
    }
}
