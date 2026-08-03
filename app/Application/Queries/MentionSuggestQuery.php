<?php

namespace App\Application\Queries;

use App\Federation\Actors\Actor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Suggerimenti per l'autocomplete delle menzioni nel composer: Person
 * locali e remoti gia' in cache, filtrati per prefisso di username
 * (e opzionalmente di dominio dopo il secondo "@").
 */
final class MentionSuggestQuery
{
    public const DEFAULT_LIMIT = 8;

    /**
     * @return Collection<int, Actor>
     */
    public function forPrefix(string $prefix, ?Actor $viewer = null, int $limit = 0): Collection
    {
        $prefix = ltrim(trim($prefix), '@');
        $limit = $limit > 0 ? $limit : (int) config('openbook.mentions.suggest_limit', self::DEFAULT_LIMIT);
        $minLength = (int) config('openbook.mentions.suggest_min_length', 1);

        if ($prefix === '' || mb_strlen($prefix) < $minLength) {
            return collect();
        }

        $username = $prefix;
        $domainPrefix = null;

        if (str_contains($prefix, '@')) {
            [$username, $domainPrefix] = explode('@', $prefix, 2);
            $username = mb_strtolower($username);
            $domainPrefix = mb_strtolower($domainPrefix);
        } else {
            $username = mb_strtolower($username);
        }

        if ($username === '' || preg_match('/^[a-z0-9_]{1,32}$/', $username) !== 1) {
            return collect();
        }

        $query = Actor::query()
            ->with(['user.profile'])
            ->where('type', Actor::TYPE_PERSON)
            ->where('status', Actor::STATUS_ACTIVE)
            ->when(
                $viewer !== null,
                fn (Builder $builder) => $builder->where('id', '!=', $viewer->id),
            );

        if ($domainPrefix !== null && $domainPrefix !== '') {
            $domainPattern = $this->likePrefix($domainPrefix);
            $query->where('preferred_username', $username)
                ->where(function (Builder $builder) use ($domainPattern): void {
                    $this->whereStartsWith($builder, 'domain', $domainPattern);
                });
        } else {
            $userPattern = $this->likePrefix($username);
            $query->where(function (Builder $builder) use ($userPattern, $username): void {
                $this->whereStartsWith($builder, 'preferred_username', $userPattern);
                $builder->orWhere(function (Builder $builder) use ($userPattern): void {
                    $this->whereStartsWith($builder, 'name', $userPattern);
                });
                $builder->orWhere(function (Builder $builder) use ($userPattern): void {
                    $builder->where('is_local', true)
                        ->whereHas('user.profile', function (Builder $profile) use ($userPattern): void {
                            $this->whereStartsWith($profile, 'display_name', $userPattern);
                        });
                });
            })
                ->orderByRaw(
                    'case when preferred_username = ? then 0 when preferred_username like ? escape \'!\' then 1 else 2 end',
                    [$username, $userPattern],
                );
        }

        return $query
            ->orderByDesc('is_local')
            ->orderBy('preferred_username')
            ->orderBy('domain')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    private function whereStartsWith(Builder $query, string $column, string $pattern): void
    {
        $query->whereRaw("{$query->getGrammar()->wrap($column)} LIKE ? ESCAPE '!'", [$pattern]);
    }

    private function likePrefix(string $term): string
    {
        $escaped = str_replace(
            ['!', '%', '_'],
            ['!!', '!%', '!_'],
            $term
        );

        return $escaped.'%';
    }
}
