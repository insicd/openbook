<?php

namespace App\Application\Queries;

use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Actors\RemoteActorResolver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Elenco di Actor remoti da proporre nella sezione "Mondo". Openbook non ha
 * modo di conoscere la popolarita' reale di un account nell'intero
 * fediverso (nessun indice globale, nessun conteggio follower autoritativo
 * per gli Actor remoti, vedi {@see RemoteActorResolver}):
 * la classifica si basa quindi solo su segnali visibili da *questa*
 * istanza, in ordine di priorita':
 *
 *   1. quanti Actor locali lo seguono gia' (popolarita' "vista da qui");
 *   2. a parita', la data del suo post pubblico piu' recente in cache
 *      (attivita' recente).
 *
 * Un Actor senza nessuno dei due segnali (mai un follower locale, mai un
 * post pubblico in cache) non viene proposto: non ci sarebbe nulla di
 * concreto su cui basare il suggerimento.
 */
final class PopularRemoteActorsQuery
{
    public const PREVIEW_LIMIT = 5;

    /**
     * @return Collection<int, Actor>
     */
    public function forViewer(Actor $viewer, int $limit = self::PREVIEW_LIMIT): Collection
    {
        return $this->baseQuery($viewer)
            ->limit($limit)
            ->get();
    }

    public function paginateForViewer(Actor $viewer, ?int $perPage = null): LengthAwarePaginator
    {
        $perPage ??= max(1, (int) config('openbook.federation.discover_per_page', 30));

        return $this->baseQuery($viewer)
            ->paginate($perPage)
            ->withQueryString();
    }

    private function baseQuery(Actor $viewer): Builder
    {
        $followerSignals = DB::table('follows')
            ->selectRaw('following_id as actor_id, count(*) as local_followers_count, null as last_public_post_at')
            ->where('status', Follow::STATUS_ACCEPTED)
            ->groupBy('following_id');

        $postSignals = DB::table('posts')
            ->selectRaw('actor_id, 0 as local_followers_count, max(published_at) as last_public_post_at')
            ->where('status', Post::STATUS_PUBLISHED)
            ->where('visibility', Post::VISIBILITY_PUBLIC)
            ->groupBy('actor_id');

        $signalStats = DB::query()
            ->fromSub($followerSignals->unionAll($postSignals), 'signals')
            ->select('actor_id')
            ->selectRaw('sum(local_followers_count) as local_followers_count')
            ->selectRaw('max(last_public_post_at) as last_public_post_at')
            ->groupBy('actor_id');

        return Actor::query()
            ->select('actors.*')
            ->addSelect('signal_stats.local_followers_count', 'signal_stats.last_public_post_at')
            ->joinSub($signalStats, 'signal_stats', fn ($join) => $join->on('signal_stats.actor_id', 'actors.id'))
            ->where('is_local', false)
            ->where('type', Actor::TYPE_PERSON)
            ->where('status', Actor::STATUS_ACTIVE)
            ->whereNotExists(function ($sub) use ($viewer): void {
                $sub->selectRaw('1')
                    ->from('follows as viewer_follows')
                    ->whereColumn('viewer_follows.following_id', 'actors.id')
                    ->where('viewer_follows.follower_id', $viewer->id);
            })
            ->orderByDesc('local_followers_count')
            ->orderByDesc('last_public_post_at');
    }
}
