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

    public const PAGE_SIZE = 30;

    /**
     * @return Collection<int, Actor>
     */
    public function forViewer(Actor $viewer, int $limit = self::PREVIEW_LIMIT): Collection
    {
        return $this->baseQuery($viewer)
            ->limit($limit)
            ->get();
    }

    public function paginateForViewer(Actor $viewer, int $perPage = self::PAGE_SIZE): LengthAwarePaginator
    {
        return $this->baseQuery($viewer)
            ->paginate($perPage)
            ->withQueryString();
    }

    private function baseQuery(Actor $viewer): Builder
    {
        $excludedIds = Follow::query()
            ->where('follower_id', $viewer->id)
            ->pluck('following_id')
            ->push($viewer->id);

        $localFollowersCount = DB::table('follows')
            ->selectRaw('count(*)')
            ->whereColumn('follows.following_id', 'actors.id')
            ->where('follows.status', Follow::STATUS_ACCEPTED);

        $lastPublicPostAt = DB::table('posts')
            ->selectRaw('max(posts.published_at)')
            ->whereColumn('posts.actor_id', 'actors.id')
            ->where('posts.status', Post::STATUS_PUBLISHED)
            ->where('posts.visibility', Post::VISIBILITY_PUBLIC);

        return Actor::query()
            ->select('actors.*')
            ->selectSub($localFollowersCount, 'local_followers_count')
            ->selectSub($lastPublicPostAt, 'last_public_post_at')
            ->where('is_local', false)
            ->where('type', Actor::TYPE_PERSON)
            ->where('status', Actor::STATUS_ACTIVE)
            ->whereNotIn('id', $excludedIds)
            ->where(function ($query) {
                $query->whereExists(function ($sub) {
                    $sub->selectRaw('1')
                        ->from('follows')
                        ->whereColumn('follows.following_id', 'actors.id')
                        ->where('follows.status', Follow::STATUS_ACCEPTED);
                })->orWhereExists(function ($sub) {
                    $sub->selectRaw('1')
                        ->from('posts')
                        ->whereColumn('posts.actor_id', 'actors.id')
                        ->where('posts.status', Post::STATUS_PUBLISHED)
                        ->where('posts.visibility', Post::VISIBILITY_PUBLIC);
                });
            })
            ->orderByDesc('local_followers_count')
            ->orderByDesc('last_public_post_at');
    }
}
