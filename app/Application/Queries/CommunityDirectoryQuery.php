<?php

namespace App\Application\Queries;

use App\Application\Services\DomainBlockManager;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/** Tre viste della directory Group con lo stesso ordinamento per handle. */
final class CommunityDirectoryQuery
{
    public const PER_PAGE = 20;

    public function __construct(private readonly DomainBlockManager $domainBlocks) {}

    /** @return LengthAwarePaginator<Actor> */
    public function paginate(string $scope, ?Actor $viewer): LengthAwarePaginator
    {
        if ($scope === 'mine' && $viewer === null) {
            return new LengthAwarePaginator([], 0, self::PER_PAGE);
        }

        $query = Actor::query()
            ->with('community')
            ->where('type', Actor::TYPE_GROUP)
            ->where('status', Actor::STATUS_ACTIVE);

        if ($scope === 'mine') {
            $query->whereIn('actors.id', $this->followedGroupIds($viewer, [Follow::STATUS_ACCEPTED]));
        } elseif ($scope === 'local') {
            $query->where('is_local', true)
                ->whereHas('community', function (Builder $communities) use ($viewer): void {
                    if ($viewer?->user?->isStaff()) {
                        return;
                    }

                    $communities->where(function (Builder $visible) use ($viewer): void {
                        $visible->where('is_private', false);

                        if ($viewer !== null) {
                            $visible->orWhere('owner_user_id', $viewer->user_id)
                                ->orWhereIn('actor_id', $this->followedGroupIds($viewer, [Follow::STATUS_ACCEPTED]));
                        }
                    });
                });
        } else {
            $query->where('is_local', false)
                ->where(function (Builder $visible) use ($viewer): void {
                    $visible->where('discoverable', true);

                    if ($viewer !== null) {
                        $visible->orWhereIn('actors.id', $this->followedGroupIds($viewer, [
                            Follow::STATUS_ACCEPTED,
                            Follow::STATUS_PENDING,
                        ]));
                    }
                });
        }

        $blockedDomains = $scope === 'local' ? [] : $this->domainBlocks->blockedHosts();

        if ($blockedDomains !== []) {
            $query->where(function (Builder $allowed) use ($blockedDomains): void {
                $allowed->where('is_local', true)->orWhereNotIn('domain', $blockedDomains);
            });
        }

        return $query
            ->orderByRaw('LOWER(actors.preferred_username)')
            ->orderByRaw('LOWER(actors.domain)')
            ->orderBy('actors.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /** @param list<string> $statuses */
    private function followedGroupIds(?Actor $viewer, array $statuses): Builder
    {
        return Follow::query()
            ->select('following_id')
            ->where('follower_id', $viewer?->id ?? '')
            ->whereIn('status', $statuses);
    }
}
