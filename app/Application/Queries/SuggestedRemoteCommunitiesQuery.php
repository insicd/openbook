<?php

namespace App\Application\Queries;

use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Group remoti gia' seguiti da utenti locali: suggerimenti per scoprire
 * community federate note a questa istanza (classifica per iscritti locali).
 */
final class SuggestedRemoteCommunitiesQuery
{
    public const INDEX_LIMIT = 10;

    /**
     * @return Collection<int, Actor>
     */
    public function forViewer(?Actor $viewer, int $limit = self::INDEX_LIMIT): Collection
    {
        $localMembersCount = DB::table('follows')
            ->join('actors as local_followers', 'local_followers.id', '=', 'follows.follower_id')
            ->selectRaw('count(*)')
            ->whereColumn('follows.following_id', 'actors.id')
            ->where('follows.status', Follow::STATUS_ACCEPTED)
            ->where('local_followers.is_local', true)
            ->where('local_followers.type', Actor::TYPE_PERSON);

        $query = Actor::query()
            ->select('actors.*')
            ->selectSub($localMembersCount, 'local_members_count')
            ->where('actors.type', Actor::TYPE_GROUP)
            ->where('actors.is_local', false)
            ->where('actors.status', Actor::STATUS_ACTIVE)
            ->whereExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('follows')
                    ->join('actors as local_followers', 'local_followers.id', '=', 'follows.follower_id')
                    ->whereColumn('follows.following_id', 'actors.id')
                    ->where('follows.status', Follow::STATUS_ACCEPTED)
                    ->where('local_followers.is_local', true)
                    ->where('local_followers.type', Actor::TYPE_PERSON);
            });

        if ($viewer !== null) {
            $query->whereNotIn('actors.id', Follow::query()
                ->select('following_id')
                ->where('follower_id', $viewer->id)
                ->whereIn('status', [Follow::STATUS_ACCEPTED, Follow::STATUS_PENDING]));
        }

        return $query
            ->orderByDesc('local_members_count')
            ->orderBy('preferred_username')
            ->orderBy('domain')
            ->limit($limit)
            ->get();
    }
}
