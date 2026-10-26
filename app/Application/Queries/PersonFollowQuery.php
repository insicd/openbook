<?php

namespace App\Application\Queries;

use App\Federation\Actors\Actor;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;

final class PersonFollowQuery
{
    public function __construct(
        private readonly PersonQuery $persons,
        private readonly FollowListQuery $follows,
    ) {}

    /** @return array{items: Collection<int, Actor>, hasMore: bool} */
    public function page(Actor $owner, bool $followers, int $limit, ?string $after, string $direction): array
    {
        $items = $this->persons->available()
            // Private community membership must not be revealed by another account's lists.
            ->whereDoesntHave('community', fn ($query) => $query->where('is_private', true))
            ->joinSub($this->follows->memberIdsQuery($owner, $followers), 'person_memberships', static function (JoinClause $join): void {
                $join->on('actors.id', '=', 'person_memberships.actor_id');
            })
            ->select('actors.*')
            ->with('user.profile')
            ->when($after !== null, fn ($query) => $query->where('actors.id', $direction === 'asc' ? '>' : '<', $after))
            ->orderBy('actors.id', $direction)
            ->limit($limit + 1)
            ->get();

        return ['items' => $items->take($limit)->values(), 'hasMore' => $items->count() > $limit];
    }
}
