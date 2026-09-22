<?php

namespace App\Federation\Serialization;

use App\Domain\Federation\Relay;
use App\Federation\Actors\Actor;
use Illuminate\Support\Str;

final class RelayActivitySerializer
{
    public const PUBLIC_STREAM = 'https://www.w3.org/ns/activitystreams#Public';

    public static function newFollowActivityUri(): string
    {
        return url('/activities/relay-follows/'.Str::uuid());
    }

    /** @return array<string, mixed> */
    public static function follow(Relay $relay, Actor $serviceActor, ?string $activityUri = null): array
    {
        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $activityUri ?? $relay->follow_activity_uri ?? self::newFollowActivityUri(),
            'type' => 'Follow',
            'actor' => $serviceActor->activityPubId(),
            'object' => self::followTarget($relay),
        ];
    }

    /** @return array<string, mixed> */
    public static function undoFollow(Relay $relay, Actor $serviceActor): array
    {
        $followActivityUri = $relay->follow_activity_uri ?? self::newFollowActivityUri();

        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $followActivityUri.'/undo',
            'type' => 'Undo',
            'actor' => $serviceActor->activityPubId(),
            'object' => self::follow($relay, $serviceActor, $followActivityUri),
        ];
    }

    /** @return array<string, mixed> */
    public static function announceObject(
        Actor $serviceActor,
        string $objectUri,
        string $sourceActivityUri,
        ?string $published = null,
    ): array {
        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => url('/relay/activities/announces/'.hash('sha256', $sourceActivityUri)),
            'type' => 'Announce',
            'actor' => $serviceActor->activityPubId(),
            'published' => $published ?? now()->toAtomString(),
            'to' => [url('/relay/followers')],
            'object' => $objectUri,
        ];
    }

    private static function followTarget(Relay $relay): string
    {
        return $relay->usesActorHandshake() && filled($relay->actor_uri)
            ? $relay->actor_uri
            : self::PUBLIC_STREAM;
    }
}
