<?php

namespace App\Application\Queries;

use App\Application\Services\AnnounceManager;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Federation\Inbox\InboxActivityProcessor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
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
     * Aggiunta come criterio di ordinamento secondario dopo la data (di
     * pubblicazione o di condivisione a seconda del metodo) ovunque i
     * risultati siano paginati: con LIMIT/OFFSET, un ORDER BY che puo'
     * avere valori uguali fra piu' righe (es. post pubblicati nello stesso
     * secondo, tutt'altro che raro con lo scorrimento infinito, che
     * richiede pagine coerenti fra una richiesta e la successiva) non
     * garantisce un ordine stabile: pagine diverse potrebbero restituire la
     * stessa riga due volte, o saltarne una. L'id non ha alcun significato
     * cronologico (e' un UUID casuale, non un contatore), ma essendo unico
     * per riga rende l'ordinamento complessivo deterministico.
     */
    private const TIEBREAKER_COLUMN = 'id';

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

        $query = Post::query()
            ->with(Post::CARD_RELATIONS)
            ->where('status', Post::STATUS_PUBLISHED)
            ->where(function ($query) use ($relevantActorIds, $announcedPostIds) {
                $query->whereIn('actor_id', $relevantActorIds)
                    ->orWhereIn('id', $announcedPostIds);
            })
            ->visibleTo($viewer);

        $posts = $this->withShareMetadata($query, $relevantActorIds)
            ->orderByRaw('coalesce(shared_at, published_at) desc')
            ->orderByDesc('posts.'.self::TIEBREAKER_COLUMN)
            ->paginate($perPage);

        Post::attachSharedBy($posts->getCollection());

        return $posts;
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
            ->with(Post::CARD_RELATIONS)
            ->where('status', Post::STATUS_PUBLISHED)
            ->where('visibility', Post::VISIBILITY_PUBLIC)
            ->whereHas('actor', fn ($query) => $query->where('is_local', true))
            ->orderByDesc('published_at')
            ->orderByDesc(self::TIEBREAKER_COLUMN)
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
            ->with(Post::CARD_RELATIONS)
            ->where('status', Post::STATUS_PUBLISHED)
            ->where('visibility', Post::VISIBILITY_PUBLIC)
            ->whereHas('actor', fn ($query) => $query->where('is_local', false))
            ->orderByDesc('published_at')
            ->orderByDesc(self::TIEBREAKER_COLUMN)
            ->paginate($perPage);
    }

    /**
     * Post del profilo: quelli di cui l'Actor e' autore, piu' quelli che ha
     * semplicemente condiviso (vedi {@see AnnounceManager}).
     * Una condivisione compare in cima o in fondo secondo il momento in cui
     * e' stata fatta, non la data di pubblicazione originale del post: e'
     * quello, dal punto di vista di chi guarda questo profilo, il fatto
     * "recente" da mostrare.
     *
     * @return LengthAwarePaginator<int, Post>
     */
    public function forProfile(Actor $profileActor, ?Actor $viewer): LengthAwarePaginator
    {
        $announcedPostIds = DB::table('announces')
            ->where('actor_id', $profileActor->id)
            ->pluck('post_id');

        $query = Post::query()
            ->with(Post::CARD_RELATIONS)
            ->where('status', Post::STATUS_PUBLISHED)
            ->where(function ($query) use ($profileActor, $announcedPostIds) {
                $query->where('actor_id', $profileActor->id)
                    ->orWhereIn('id', $announcedPostIds);
            })
            ->visibleTo($viewer);

        $posts = $this->withShareMetadata($query, collect([$profileActor->id]))
            ->orderByRaw('coalesce(shared_at, published_at) desc')
            ->orderByDesc('posts.'.self::TIEBREAKER_COLUMN)
            ->paginate((int) config('openbook.feed.per_page'));

        Post::attachSharedBy($posts->getCollection());

        return $posts;
    }

    /**
     * Arricchisce la query con due colonne virtuali calcolate da subquery
     * correlate su "announces", limitate agli Actor indicati in
     * "$sharerIds": "shared_by_actor_id" (chi, fra loro, ha condiviso questo
     * post piu' di recente) e "shared_at" (quando). Un post puo' comparire
     * in questo risultato sia perche' ne e' autore un Actor rilevante sia
     * perche' e' stato condiviso da uno di loro (o entrambe le cose): in
     * quest'ultimo caso {@see Post::attachSharedBy()} decide se mostrare
     * comunque l'indicazione "ha condiviso".
     *
     * @param  Builder<Post>  $query
     * @param  Collection<int, string>  $sharerIds
     * @return Builder<Post>
     */
    private function withShareMetadata(Builder $query, Collection $sharerIds): Builder
    {
        $announcerId = DB::table('announces')
            ->select('actor_id')
            ->whereColumn('post_id', 'posts.id')
            ->whereIn('actor_id', $sharerIds)
            ->orderByDesc('created_at')
            ->limit(1);

        $announcedAt = DB::table('announces')
            ->select('created_at')
            ->whereColumn('post_id', 'posts.id')
            ->whereIn('actor_id', $sharerIds)
            ->orderByDesc('created_at')
            ->limit(1);

        return $query
            ->addSelect(['shared_by_actor_id' => $announcerId])
            ->addSelect(['shared_at' => $announcedAt]);
    }
}
