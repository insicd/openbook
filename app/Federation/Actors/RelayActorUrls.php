<?php

namespace App\Federation\Actors;

final class RelayActorUrls
{
    /** @return array{uri: string, inbox: string, outbox: string, shared_inbox: string} */
    public static function all(): array
    {
        return [
            'uri' => url('/relay'),
            'inbox' => url('/inbox'),
            'outbox' => url('/relay/outbox'),
            'shared_inbox' => url('/inbox'),
        ];
    }
}
