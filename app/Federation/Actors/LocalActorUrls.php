<?php

namespace App\Federation\Actors;

/**
 * URL ActivityPub e di profilo per un Actor locale.
 *
 * L'identificatore ActivityPub canonico e' "/users/{username}" (stesso
 * schema di Mastodon): evita il carattere "@" nel path, che Lemmy e altri
 * client percent-encodano (%40) e poi rifiutano perche' l'URL richiesto non
 * coincide con il campo "id" del documento. La pagina HTML resta "/@..." o
 * "/c/..." tramite {@see self::profile()}.
 */
final class LocalActorUrls
{
    /**
     * @return array{uri: string, profile: string, inbox: string, outbox: string, followers: string, following: string, shared_inbox: string}
     */
    public static function forUsername(string $username, bool $isGroup = false): array
    {
        return [
            'uri' => url('/users/'.$username),
            'profile' => $isGroup ? url('/c/'.$username) : url('/@'.$username),
            'inbox' => url("/users/{$username}/inbox"),
            'outbox' => url("/users/{$username}/outbox"),
            'followers' => url("/users/{$username}/followers"),
            'following' => url("/users/{$username}/following"),
            'shared_inbox' => url('/inbox'),
        ];
    }
}
