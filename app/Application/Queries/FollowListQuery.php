<?php

namespace App\Application\Queries;

use App\Application\Services\FollowManager;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Costruisce gli elenchi paginati di follower e "seguiti" di un Actor,
 * locale o remoto: la relazione Follow e' generica (Actor-to-Actor) fin dal
 * Milestone 2, quindi la stessa query funziona senza distinzioni in entrambi
 * i casi (vedi anche {@see FollowManager}).
 */
final class FollowListQuery
{
    /**
     * @return LengthAwarePaginator<int, Actor>
     */
    public function followers(Actor $actor, int $perPage = 0): LengthAwarePaginator
    {
        $perPage = $perPage > 0 ? $perPage : (int) config('openbook.feed.per_page');

        $paginator = Follow::query()
            ->where('following_id', $actor->id)
            ->where('status', Follow::STATUS_ACCEPTED)
            ->with('follower.user.profile')
            ->orderByDesc('accepted_at')
            ->paginate($perPage);

        return $this->mapToActors($paginator, 'follower');
    }

    /**
     * @return LengthAwarePaginator<int, Actor>
     */
    public function following(Actor $actor, int $perPage = 0): LengthAwarePaginator
    {
        $perPage = $perPage > 0 ? $perPage : (int) config('openbook.feed.per_page');

        $paginator = Follow::query()
            ->where('follower_id', $actor->id)
            ->where('status', Follow::STATUS_ACCEPTED)
            ->with('following.user.profile')
            ->orderByDesc('accepted_at')
            ->paginate($perPage);

        return $this->mapToActors($paginator, 'following');
    }

    /**
     * @param  LengthAwarePaginator<int, Follow>  $paginator
     * @return LengthAwarePaginator<int, Actor>
     */
    private function mapToActors(LengthAwarePaginator $paginator, string $relation): LengthAwarePaginator
    {
        $actors = $paginator->getCollection()
            ->map(fn (Follow $follow) => $follow->{$relation})
            ->values();

        return new LengthAwarePaginator(
            $actors,
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
        );
    }
}
