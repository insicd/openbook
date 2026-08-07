<?php

namespace App\Domain\Posts;

use App\Federation\Actors\Actor;
use Illuminate\Support\Collection;

/**
 * Estrae hashtag e menzioni dal testo grezzo di un post o commento.
 *
 * Le menzioni verso attori locali e verso attori remoti gia' presenti in
 * cache (es. una community Group visitata in precedenza) vengono risolte
 * senza ulteriori round-trip HTTP. Un "@utente@dominio" ancora sconosciuto
 * a questa istanza viene ignorato silenziosamente: la risoluzione via
 * WebFinger al momento della scrittura resta fuori dallo scope di questo
 * parser.
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
            ->filter(fn (string $name) => Hashtag::isValidName($name))
            ->unique()
            ->values();
    }

    /**
     * Risolve le menzioni presenti nel testo in Actor locali o remoti in cache.
     *
     * @return Collection<int, Actor>
     */
    public function extractMentionedActors(string $body): Collection
    {
        preg_match_all(self::MENTION_PATTERN, $body, $matches, PREG_SET_ORDER);

        if ($matches === []) {
            return collect();
        }

        $localDomain = (string) config('openbook.domain');
        $handles = collect($matches)
            ->map(function (array $match) use ($localDomain): array {
                $username = mb_strtolower($match[1]);
                $domain = $match[2] ?? '';

                if ($domain === '' || strcasecmp($domain, $localDomain) === 0) {
                    return ['username' => $username, 'domain' => mb_strtolower($localDomain), 'local' => true];
                }

                return ['username' => $username, 'domain' => mb_strtolower($domain), 'local' => false];
            })
            ->unique(fn (array $handle) => $handle['username'].'@'.$handle['domain'])
            ->values();

        if ($handles->isEmpty()) {
            return collect();
        }

        $localUsernames = $handles->where('local', true)->pluck('username')->all();
        $remotePairs = $handles->where('local', false)->values();

        $actors = collect();

        if ($localUsernames !== []) {
            $actors = $actors->concat(
                Actor::query()
                    ->where('is_local', true)
                    ->where('status', Actor::STATUS_ACTIVE)
                    ->whereIn('preferred_username', $localUsernames)
                    ->get()
            );
        }

        foreach ($remotePairs as $pair) {
            $remote = Actor::query()
                ->where('is_local', false)
                ->where('status', Actor::STATUS_ACTIVE)
                ->where('preferred_username', $pair['username'])
                ->where('domain', $pair['domain'])
                ->first();

            if ($remote !== null) {
                $actors->push($remote);
            }
        }

        return $actors->unique('id')->values();
    }
}
