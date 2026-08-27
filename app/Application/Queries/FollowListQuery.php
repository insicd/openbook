<?php

namespace App\Application\Queries;

use App\Application\Services\FollowManager;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\SocialGraph\RemoteCollectionMember;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

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
     * Campione della collection remota gia' in cache (prima pagina).
     * Esclude chi e' gia' nel grafo locale, per non duplicare le righe.
     *
     * @return Collection<int, Actor>
     */
    public function remotePreview(Actor $actor, string $type): Collection
    {
        $collection = $type === 'followers'
            ? RemoteCollectionMember::COLLECTION_FOLLOWERS
            : RemoteCollectionMember::COLLECTION_FOLLOWING;

        $localIds = $type === 'followers'
            ? Follow::query()
                ->where('following_id', $actor->id)
                ->where('status', Follow::STATUS_ACCEPTED)
                ->pluck('follower_id')
            : Follow::query()
                ->where('follower_id', $actor->id)
                ->where('status', Follow::STATUS_ACCEPTED)
                ->pluck('following_id');

        return RemoteCollectionMember::query()
            ->where('actor_id', $actor->id)
            ->where('collection', $collection)
            ->whereNotNull('member_actor_id')
            ->when($localIds->isNotEmpty(), fn ($query) => $query->whereNotIn('member_actor_id', $localIds))
            ->with('member.user.profile')
            ->orderBy('position')
            ->get()
            ->map(fn (RemoteCollectionMember $row) => $row->member)
            ->filter()
            ->values();
    }

    /**
     * @param  LengthAwarePaginator<int, Follow>  $paginator
     * @return LengthAwarePaginator<int, Actor>
     */
    private function mapToActors(LengthAwarePaginator $paginator, string $relation): LengthAwarePaginator
    {
        // setCollection mantiene path/query della paginazione originale:
        // ricostruire un LengthAwarePaginator "a mano" spezzava nextPageUrl()
        // (e quindi sia le frecce sia l'infinite scroll).
        $actors = $paginator->getCollection()
            ->map(fn (Follow $follow) => $follow->{$relation})
            ->values();

        /** @var LengthAwarePaginator<int, Actor> $paginator */
        $paginator->setCollection($actors);

        return $paginator;
    }
}
