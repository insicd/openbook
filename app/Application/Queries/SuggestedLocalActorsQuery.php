<?php

namespace App\Application\Queries;

use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use Illuminate\Support\Collection;

/**
 * Persone locali discoverable da proporre ("Persone da seguire"): esclude
 * il viewer e chi ha gia' un Follow (qualsiasi stato) verso di loro.
 */
final class SuggestedLocalActorsQuery
{
    public const SIDEBAR_LIMIT = 5;

    public const WELCOME_LIMIT = 6;

    /**
     * @param  iterable<int, string|int>|null  $extraExcludedActorIds
     * @return Collection<int, Actor>
     */
    public function forViewer(Actor $viewer, int $limit = self::SIDEBAR_LIMIT, ?iterable $extraExcludedActorIds = null): Collection
    {
        $excludedIds = Follow::query()
            ->where('follower_id', $viewer->id)
            ->pluck('following_id')
            ->push($viewer->id);

        if ($extraExcludedActorIds !== null) {
            $excludedIds = $excludedIds->concat(collect($extraExcludedActorIds))->unique()->values();
        }

        return Actor::query()
            ->where('is_local', true)
            ->where('type', Actor::TYPE_PERSON)
            ->where('status', Actor::STATUS_ACTIVE)
            ->whereNotIn('id', $excludedIds)
            ->whereHas('user.settings', fn ($query) => $query->where('discoverable', true))
            ->with('user.profile')
            ->latest('created_at')
            ->limit($limit)
            ->get();
    }
}
