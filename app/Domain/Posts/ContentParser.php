<?php

namespace App\Domain\Posts;

use App\Federation\Actors\Actor;
use Illuminate\Support\Collection;

/**
 * Estrae hashtag e menzioni dal testo grezzo di un post o commento.
 *
 * Per questo milestone vengono risolte solo le menzioni verso attori
 * *locali*: la risoluzione di un "@utente@dominio-remoto" richiede di
 * interrogare il dominio remoto (WebFinger), funzione che arriva con la
 * federazione (Fase 3/4). Le menzioni verso domini remoti vengono quindi
 * ignorate silenziosamente in questa fase, senza generare errori.
 */
final class ContentParser
{
    private const HASHTAG_PATTERN = '/(?<![\w\/])#([\p{L}\p{N}_]{1,100})/u';

    private const MENTION_PATTERN = '/(?<![\w])@([a-zA-Z0-9_]{1,32})(?:@([a-zA-Z0-9.\-]+))?/';

    /**
     * @return Collection<int, string> nomi normalizzati (minuscoli, senza "#")
     */
    public function extractHashtagNames(string $body): Collection
    {
        preg_match_all(self::HASHTAG_PATTERN, $body, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $tag) => Hashtag::normalize($tag))
            ->unique()
            ->values();
    }

    /**
     * Risolve le menzioni locali presenti nel testo in Actor esistenti.
     *
     * @return Collection<int, Actor>
     */
    public function extractLocalMentionedActors(string $body): Collection
    {
        preg_match_all(self::MENTION_PATTERN, $body, $matches, PREG_SET_ORDER);

        $localDomain = (string) config('openbook.domain');

        $usernames = collect($matches)
            ->filter(function (array $match) use ($localDomain) {
                $domain = $match[2] ?? '';

                return $domain === '' || strcasecmp($domain, $localDomain) === 0;
            })
            ->map(fn (array $match) => mb_strtolower($match[1]))
            ->unique()
            ->values();

        if ($usernames->isEmpty()) {
            return collect();
        }

        return Actor::query()
            ->where('is_local', true)
            ->whereIn('preferred_username', $usernames->all())
            ->get();
    }
}
