<?php

namespace App\Federation\Actors;

/**
 * URL ActivityPub canonici per un Actor locale, sempre derivati da
 * {@see url()} / APP_URL corrente. Evita di pubblicare inbox/outbox su un
 * host diverso dall'"id" dell'Actor (es. dominio vecchio rimasto in
 * "actor_endpoints"), situazione che Lemmy rifiuta in fase di Follow.
 */
final class LocalActorUrls
{
    /**
     * @return array{uri: string, inbox: string, outbox: string, followers: string, following: string, shared_inbox: string}
     */
    public static function forUsername(string $username): array
    {
        return [
            'uri' => url('/@'.$username),
            'inbox' => url("/users/{$username}/inbox"),
            'outbox' => url("/users/{$username}/outbox"),
            'followers' => url("/users/{$username}/followers"),
            'following' => url("/users/{$username}/following"),
            'shared_inbox' => url('/inbox'),
        ];
    }
}
