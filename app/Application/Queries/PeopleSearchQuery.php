<?php

namespace App\Application\Queries;

use App\Domain\Accounts\User;
use App\Federation\Actors\Actor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Profili conosciuti dall'istanza (persone, bot, feed RSS e community),
 * con le stesse regole per ricerca e suggerimenti. Nessun recupero
 * federato avviene mentre si digita.
 */
final class PeopleSearchQuery
{
    /** @return Collection<int, Actor> */
    public function search(string $input, int $limit, int $minLength = 2): Collection
    {
        $term = preg_replace('/\s+/u', ' ', trim($input)) ?? trim($input);
        $term = ltrim($term, '@');

        if ($term === '' || mb_strlen($term) < $minLength || str_starts_with($term, '#')) {
            return collect();
        }

        $query = Actor::query()
            ->select('actors.*')
            ->with('user.profile')
            ->leftJoin('users', 'users.id', '=', 'actors.user_id')
            ->leftJoin('user_settings', 'user_settings.user_id', '=', 'users.id')
            ->leftJoin('profiles', 'profiles.user_id', '=', 'users.id')
            ->leftJoin('communities', 'communities.actor_id', '=', 'actors.id')
            ->whereIn('actors.type', [
                Actor::TYPE_PERSON,
                Actor::TYPE_APPLICATION,
                Actor::TYPE_FEED,
                Actor::TYPE_GROUP,
            ])
            ->where('actors.status', Actor::STATUS_ACTIVE)
            ->where(function (Builder $query): void {
                $query->where('actors.is_local', true)
                    ->orWhere('actors.remote_suspended', false);
            })
            ->where(function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->where('actors.type', Actor::TYPE_PERSON)
                        ->where('actors.is_local', true)
                        ->where('users.status', User::STATUS_ACTIVE)
                        ->where('user_settings.discoverable', true);
                })->orWhere(function (Builder $query): void {
                    $query->where('actors.type', Actor::TYPE_PERSON)
                        ->where('actors.is_local', false)
                        ->where('actors.discoverable', true);
                })->orWhere(function (Builder $query): void {
                    $query->where('actors.type', Actor::TYPE_APPLICATION)
                        ->where('actors.is_local', false);
                })->orWhere(function (Builder $query): void {
                    $query->where('actors.type', Actor::TYPE_FEED);
                })->orWhere(function (Builder $query): void {
                    $query->where('actors.type', Actor::TYPE_GROUP)
                        ->where(function (Builder $query): void {
                            $query->where('actors.is_local', false)
                                ->orWhere('communities.is_private', false);
                        });
                });
            });

        $displayName = "case when actors.is_local = 1 and actors.type = '".Actor::TYPE_PERSON."' then profiles.display_name else actors.name end";

        if (preg_match('/^([^@\s]+)@([^@\s]*)$/u', $term, $handle) === 1) {
            $username = mb_strtolower($handle[1]);
            $domain = mb_strtolower($handle[2]);
            $query->where('actors.preferred_username', $username)
                ->whereRaw("actors.domain LIKE ? ESCAPE '!'", [$this->prefix($domain)])
                ->orderByRaw('case when actors.domain = ? then 0 else 1 end', [$domain]);
        } else {
            $username = mb_strtolower($term);
            $usernamePrefix = $this->prefix($username);
            $usernameContains = $this->contains($username);
            $namePrefix = $this->prefix($term);
            $nameContains = $this->contains($term);

            $query->where(function (Builder $query) use ($usernameContains, $nameContains, $displayName): void {
                $query->whereRaw("actors.preferred_username LIKE ? ESCAPE '!'", [$usernameContains])
                    ->orWhereRaw("{$displayName} LIKE ? ESCAPE '!'", [$nameContains])
                    ->orWhereRaw("profiles.bio LIKE ? ESCAPE '!'", [$nameContains])
                    ->orWhere(function (Builder $query) use ($nameContains): void {
                        $query->where('actors.type', Actor::TYPE_FEED)
                            ->whereRaw("actors.summary LIKE ? ESCAPE '!'", [$nameContains]);
                    });
            })->orderByRaw(
                "case when actors.preferred_username = ? then 0
                    when actors.preferred_username LIKE ? ESCAPE '!' then 1
                    when {$displayName} LIKE ? ESCAPE '!' then 2
                    when {$displayName} LIKE ? ESCAPE '!' then 3
                    when actors.preferred_username LIKE ? ESCAPE '!' then 4
                    else 5 end",
                [$username, $usernamePrefix, $namePrefix, $nameContains, $usernameContains],
            );
        }

        return $query
            ->orderBy('actors.preferred_username')
            ->orderBy('actors.domain')
            ->limit($limit)
            ->get();
    }

    private function prefix(string $term): string
    {
        return $this->escape($term).'%';
    }

    private function contains(string $term): string
    {
        return '%'.$this->escape($term).'%';
    }

    private function escape(string $term): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
    }
}
