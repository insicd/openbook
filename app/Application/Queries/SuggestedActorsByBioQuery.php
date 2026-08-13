<?php

namespace App\Application\Queries;

use App\Domain\Accounts\User;
use App\Domain\Posts\Hashtag;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Suggerimenti "Persone da seguire" in base a bio/riassunto: cerca tra gli
 * Actor Person conosciuti da questa istanza (locali discoverable + remoti
 * in cache) che menzionano un hashtag o una keyword nel profilo.
 */
final class SuggestedActorsByBioQuery
{
    public const SIDEBAR_LIMIT = 5;

    /**
     * @return Collection<int, Actor>
     */
    public function forViewer(Actor $viewer, string $term, int $limit = self::SIDEBAR_LIMIT): Collection
    {
        $term = ltrim(trim($term), '#');

        if ($term === '' || mb_strlen($term) < (int) config('openbook.search.min_length', 2)) {
            return collect();
        }

        $normalized = Hashtag::normalize($term);
        $pattern = $this->likePattern($term);
        $normalizedPattern = $normalized !== $term ? $this->likePattern($normalized) : $pattern;

        $excludedIds = Follow::query()
            ->where('follower_id', $viewer->id)
            ->pluck('following_id')
            ->push($viewer->id);

        $local = $this->localMatches($excludedIds, $pattern, $normalizedPattern, $limit);
        $remote = $this->remoteMatches($excludedIds, $pattern, $normalizedPattern, $limit);

        return $local
            ->concat($remote)
            ->unique('id')
            ->sortByDesc(fn (Actor $actor): int => $this->matchScore($actor, $term, $normalized))
            ->take($limit)
            ->values();
    }

    /**
     * @param  Collection<int, string|int>  $excludedIds
     * @return Collection<int, Actor>
     */
    private function localMatches(Collection $excludedIds, string $pattern, string $normalizedPattern, int $limit): Collection
    {
        return Actor::query()
            ->select('actors.*')
            ->join('users', 'users.id', '=', 'actors.user_id')
            ->join('user_settings', 'user_settings.user_id', '=', 'users.id')
            ->leftJoin('profiles', 'profiles.user_id', '=', 'users.id')
            ->where('actors.is_local', true)
            ->where('actors.type', Actor::TYPE_PERSON)
            ->where('actors.status', Actor::STATUS_ACTIVE)
            ->where('users.status', User::STATUS_ACTIVE)
            ->where('user_settings.discoverable', true)
            ->whereNotIn('actors.id', $excludedIds)
            ->where(function (Builder $query) use ($pattern, $normalizedPattern): void {
                $this->applyProfileTextMatch($query, $pattern, $normalizedPattern);
            })
            ->with('user.profile')
            ->orderByRaw(
                'case when profiles.bio like ? escape \'!\' then 0 when profiles.display_name like ? escape \'!\' then 1 else 2 end',
                [$pattern, $pattern]
            )
            ->orderBy('actors.preferred_username')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  Collection<int, string|int>  $excludedIds
     * @return Collection<int, Actor>
     */
    private function remoteMatches(Collection $excludedIds, string $pattern, string $normalizedPattern, int $limit): Collection
    {
        $localFollowersCount = DB::table('follows')
            ->selectRaw('count(*)')
            ->whereColumn('follows.following_id', 'actors.id')
            ->where('follows.status', Follow::STATUS_ACCEPTED);

        return Actor::query()
            ->select('actors.*')
            ->selectSub($localFollowersCount, 'local_followers_count')
            ->where('actors.is_local', false)
            ->where('actors.type', Actor::TYPE_PERSON)
            ->where('actors.status', Actor::STATUS_ACTIVE)
            ->whereNotIn('actors.id', $excludedIds)
            ->where(function (Builder $query) use ($pattern, $normalizedPattern): void {
                $this->whereContains($query, 'actors.summary', $pattern);
                $query->orWhere(function (Builder $query) use ($pattern): void {
                    $this->whereContains($query, 'actors.name', $pattern);
                });
                $query->orWhere(function (Builder $query) use ($pattern): void {
                    $this->whereContains($query, 'actors.preferred_username', $pattern);
                });

                if ($normalizedPattern !== $pattern) {
                    $query->orWhere(function (Builder $query) use ($normalizedPattern): void {
                        $this->whereContains($query, 'actors.summary', $normalizedPattern);
                    });
                }
            })
            ->orderByRaw(
                'case when actors.summary like ? escape \'!\' then 0 when actors.name like ? escape \'!\' then 1 else 2 end',
                [$pattern, $pattern]
            )
            ->orderByDesc('local_followers_count')
            ->orderBy('actors.preferred_username')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applyProfileTextMatch(Builder $query, string $pattern, string $normalizedPattern): void
    {
        $this->whereContains($query, 'profiles.bio', $pattern);
        $query->orWhere(function (Builder $query) use ($pattern): void {
            $this->whereContains($query, 'profiles.display_name', $pattern);
        });
        $query->orWhere(function (Builder $query) use ($pattern): void {
            $this->whereContains($query, 'actors.preferred_username', $pattern);
        });

        if ($normalizedPattern !== $pattern) {
            $query->orWhere(function (Builder $query) use ($normalizedPattern): void {
                $this->whereContains($query, 'profiles.bio', $normalizedPattern);
            });
        }
    }

    private function matchScore(Actor $actor, string $term, string $normalized): int
    {
        $haystacks = [
            $actor->isLocal() ? ($actor->user?->profile?->bio ?? '') : ($actor->summary ?? ''),
            $actor->isLocal() ? ($actor->user?->profile?->display_name ?? '') : ($actor->name ?? ''),
            $actor->preferred_username,
        ];

        $score = 0;
        $needles = array_values(array_unique([mb_strtolower($term), mb_strtolower($normalized)]));

        foreach ($haystacks as $index => $haystack) {
            $haystack = mb_strtolower(strip_tags((string) $haystack));

            foreach ($needles as $needle) {
                if ($needle === '' || ! str_contains($haystack, $needle)) {
                    continue;
                }

                $score += match ($index) {
                    0 => 30,
                    1 => 20,
                    default => 10,
                };
            }
        }

        if (! $actor->is_local) {
            $score += min(10, (int) ($actor->getAttribute('local_followers_count') ?? 0));
        }

        return $score;
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function whereContains(Builder $query, string $column, string $pattern): void
    {
        $query->whereRaw("{$query->getGrammar()->wrap($column)} LIKE ? ESCAPE '!'", [$pattern]);
    }

    private function likePattern(string $term): string
    {
        $escaped = str_replace(
            ['!', '%', '_'],
            ['!!', '!%', '!_'],
            $term
        );

        return '%'.$escaped.'%';
    }
}
