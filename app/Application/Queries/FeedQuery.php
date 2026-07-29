<?php

namespace App\Application\Queries;

use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Federation\Inbox\InboxActivityProcessor;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Costruisce il feed personale: post propri, post degli attori seguiti e
 * condivisioni fatte dagli attori seguiti, rispettando la visibilita' e
 * ordinati in modo cronologico inverso (nessun algoritmo di
 * raccomandazione, come richiesto dal design).
 */
final class FeedQuery
{
    /**
     * @return LengthAwarePaginator<int, Post>
     */
    public function forActor(Actor $viewer, int $perPage = 0): LengthAwarePaginator
    {
        $perPage = $perPage > 0 ? $perPage : (int) config('openbook.feed.per_page');

        $followingIds = DB::table('follows')
            ->where('follower_id', $viewer->id)
            ->where('status', 'accepted')
            ->pluck('following_id');

        $relevantActorIds = $followingIds->push($viewer->id)->unique()->values();

        $announcedPostIds = DB::table('announces')
            ->whereIn('actor_id', $relevantActorIds)
            ->pluck('post_id');

        return Post::query()
            ->with(['actor.user.profile', 'media', 'hashtags'])
            ->where('status', Post::STATUS_PUBLISHED)
            ->where(function ($query) use ($relevantActorIds, $announcedPostIds) {
                $query->whereIn('actor_id', $relevantActorIds)
                    ->orWhereIn('id', $announcedPostIds);
            })
            ->visibleTo($viewer)
            ->orderByDesc('published_at')
            ->paginate($perPage);
    }

    /**
     * Feed pubblico locale: tutti i post pubblici dell'istanza, usato dalla
     * home per i visitatori non autenticati e dalla pagina "Locale".
     *
     * @return LengthAwarePaginator<int, Post>
     */
    public function local(int $perPage = 0): LengthAwarePaginator
    {
        $perPage = $perPage > 0 ? $perPage : (int) config('openbook.feed.per_page');

        return Post::query()
            ->with(['actor.user.profile', 'media', 'hashtags'])
            ->where('status', Post::STATUS_PUBLISHED)
            ->where('visibility', Post::VISIBILITY_PUBLIC)
            ->whereHas('actor', fn ($query) => $query->where('is_local', true))
            ->orderByDesc('published_at')
            ->paginate($perPage);
    }

    /**
     * Timeline "Mondo": i post pubblici di Actor *remoti* gia' in cache
     * locale, a prescindere da chi li segue. Non e' (e non puo' essere) una
     * vista completa del fediverso: Openbook mette in cache solo i contenuti
     * remoti gia' ritenuti rilevanti da {@see InboxActivityProcessor::isRelevant()}
     * (autore seguito da un Actor locale, risposta a qualcosa che gia'
     * conosciamo, o menzione di un Actor locale), quindi questa e' sempre e
     * solo una finestra parziale su cio' che e' arrivato fin qui.
     *
     * @return LengthAwarePaginator<int, Post>
     */
    public function world(int $perPage = 0): LengthAwarePaginator
    {
        $perPage = $perPage > 0 ? $perPage : (int) config('openbook.feed.per_page');

        return Post::query()
            ->with(['actor.user.profile', 'media', 'hashtags'])
            ->where('status', Post::STATUS_PUBLISHED)
            ->where('visibility', Post::VISIBILITY_PUBLIC)
            ->whereHas('actor', fn ($query) => $query->where('is_local', false))
            ->orderByDesc('published_at')
            ->paginate($perPage);
    }

    /**
     * @return LengthAwarePaginator<int, Post>
     */
    public function forProfile(Actor $profileActor, ?Actor $viewer): LengthAwarePaginator
    {
        return Post::query()
            ->with(['actor.user.profile', 'media', 'hashtags'])
            ->where('actor_id', $profileActor->id)
            ->where('status', Post::STATUS_PUBLISHED)
            ->visibleTo($viewer)
            ->orderByDesc('published_at')
            ->paginate((int) config('openbook.feed.per_page'));
    }
}
