<?php

namespace App\Application\Queries;

use App\Application\Services\AnnounceManager;
use App\Domain\Communities\Community;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Federation\Inbox\InboxActivityProcessor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
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
     *
     * Lo scorrimento infinito usa inoltre un cursore (sortAt + id) al posto
     * del numero di pagina, cosi' i post nuovi in cima alla timeline non
     * spostano l'OFFSET e non duplicano righe gia' mostrate.
     */
    private const TIEBREAKER_COLUMN = 'id';

    public function forActor(Actor $viewer, ?FeedCursor $cursor = null, int $perPage = 0): FeedPage
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

        $memberCommunityIds = DB::table('communities')
            ->whereIn('actor_id', $followingIds)
            ->pluck('id');

        $query = Post::query()
            ->with(Post::CARD_RELATIONS)
            ->excludingPrivateMessages()
            ->where('status', Post::STATUS_PUBLISHED)
            ->where(function ($query) use ($relevantActorIds, $announcedPostIds, $memberCommunityIds) {
                $query->whereIn('actor_id', $relevantActorIds)
                    ->orWhereIn('id', $announcedPostIds);

                if ($memberCommunityIds->isNotEmpty()) {
                    $query->orWhereIn('community_id', $memberCommunityIds);
                }
            })
            ->visibleTo($viewer);

        $query = $this->withShareMetadata($query, $relevantActorIds)
            ->orderByRaw('coalesce(shared_at, published_at) desc')
            ->orderByDesc('posts.'.self::TIEBREAKER_COLUMN);

        $page = $this->paginateKeyset(
            $query,
            $perPage,
            $cursor,
            useShareSortCursor: true,
            shareSortActorIds: $relevantActorIds,
        );

        Post::attachSharedBy($page->getCollection());

        return $page;
    }

    /**
     * Feed pubblico locale: tutti i post pubblici dell'istanza, usato dalla
     * home per i visitatori non autenticati e dalla pagina "Locale".
     */
    public function local(?FeedCursor $cursor = null, int $perPage = 0): FeedPage
    {
        $perPage = $perPage > 0 ? $perPage : (int) config('openbook.feed.per_page');

        $query = Post::query()
            ->with(Post::CARD_RELATIONS)
            ->excludingPrivateMessages()
            ->where('status', Post::STATUS_PUBLISHED)
            ->where('visibility', Post::VISIBILITY_PUBLIC)
            ->where(function ($query) {
                $query->whereNull('community_id')
                    ->orWhereHas('community', fn ($community) => $community->where('is_private', false));
            })
            ->whereHas('actor', fn ($query) => $query->where('is_local', true))
            ->orderByDesc('published_at')
            ->orderByDesc(self::TIEBREAKER_COLUMN);

        return $this->paginateKeyset($query, $perPage, $cursor, useShareSortCursor: false);
    }

    /**
     * Timeline "Mondo": i post pubblici di Actor *remoti* gia' in cache
     * locale, a prescindere da chi li segue. Non e' (e non puo' essere) una
     * vista completa del fediverso: Openbook mette in cache solo i contenuti
     * remoti gia' ritenuti rilevanti da {@see InboxActivityProcessor::isRelevant()}
     * (autore seguito da un Actor locale, risposta a qualcosa che gia'
     * conosciamo, o menzione di un Actor locale), quindi questa e' sempre e
     * solo una finestra parziale su cio' che e' arrivato fin qui.
     */
    public function world(?FeedCursor $cursor = null, int $perPage = 0): FeedPage
    {
        $perPage = $perPage > 0 ? $perPage : (int) config('openbook.feed.per_page');

        $query = Post::query()
            ->with(Post::CARD_RELATIONS)
            ->excludingPrivateMessages()
            ->where('status', Post::STATUS_PUBLISHED)
            ->where('visibility', Post::VISIBILITY_PUBLIC)
            ->where(function ($query) {
                $query->whereNull('community_id')
                    ->orWhereHas('community', fn ($community) => $community->where('is_private', false));
            })
            ->whereHas('actor', fn ($query) => $query->where('is_local', false))
            ->orderByDesc('published_at')
            ->orderByDesc(self::TIEBREAKER_COLUMN);

        return $this->paginateKeyset($query, $perPage, $cursor, useShareSortCursor: false);
    }

    /**
     * Wall di una community: post pubblicati verso di essa.
     */
    public function forCommunity(Community $community, ?Actor $viewer, ?FeedCursor $cursor = null): FeedPage
    {
        $query = Post::query()
            ->with(Post::CARD_RELATIONS)
            ->excludingPrivateMessages()
            ->where('status', Post::STATUS_PUBLISHED)
            ->where('community_id', $community->id)
            ->visibleTo($viewer)
            ->orderByDesc('published_at')
            ->orderByDesc(self::TIEBREAKER_COLUMN);

        return $this->paginateKeyset(
            $query,
            (int) config('openbook.feed.per_page'),
            $cursor,
            useShareSortCursor: false,
        );
    }

    /**
     * Post del profilo: quelli di cui l'Actor e' autore, piu' quelli che ha
     * semplicemente condiviso (vedi {@see AnnounceManager}).
     * Per un Person, una condivisione compare secondo il momento in cui e'
     * stata fatta (shared_at). Per un Group (wall community, anche remota)
     * si ordina sempre per data di pubblicazione del post: dopo un backfill
     * dall'outbox gli Announce locali avrebbero altrimenti un ordine
     * invertito rispetto al feed (piu' vecchi in alto).
     */
    public function forProfile(Actor $profileActor, ?Actor $viewer, ?FeedCursor $cursor = null): FeedPage
    {
        $announcedPostIds = DB::table('announces')
            ->where('actor_id', $profileActor->id)
            ->pluck('post_id');

        $query = Post::query()
            ->with(Post::CARD_RELATIONS)
            ->excludingPrivateMessages()
            ->where('status', Post::STATUS_PUBLISHED)
            ->where(function ($query) use ($profileActor, $announcedPostIds) {
                $query->where('actor_id', $profileActor->id)
                    ->orWhereIn('id', $announcedPostIds);
            })
            ->visibleTo($viewer);

        $ordered = $this->withShareMetadata($query, collect([$profileActor->id]));

        if ($profileActor->isGroup()) {
            $query = $ordered
                ->orderByDesc('posts.published_at')
                ->orderByDesc('posts.'.self::TIEBREAKER_COLUMN);

            $page = $this->paginateKeyset($query, (int) config('openbook.feed.per_page'), $cursor, useShareSortCursor: false);
        } else {
            $shareSortActorIds = collect([$profileActor->id]);
            $query = $ordered
                ->orderByRaw('coalesce(shared_at, published_at) desc')
                ->orderByDesc('posts.'.self::TIEBREAKER_COLUMN);

            $page = $this->paginateKeyset(
                $query,
                (int) config('openbook.feed.per_page'),
                $cursor,
                useShareSortCursor: true,
                shareSortActorIds: $shareSortActorIds,
            );
        }

        Post::attachSharedBy($page->getCollection());

        return $page;
    }

    /**
     * Feed per hashtag: stesso cursore dei post ordinati per published_at.
     *
     * @param  Builder<Post>  $query
     */
    public function paginatePublishedQuery(Builder $query, ?FeedCursor $cursor = null, int $perPage = 0): FeedPage
    {
        $perPage = $perPage > 0 ? $perPage : (int) config('openbook.feed.per_page');

        return $this->paginateKeyset(
            $query->orderByDesc('published_at')->orderByDesc('posts.'.self::TIEBREAKER_COLUMN),
            $perPage,
            $cursor,
            useShareSortCursor: false,
        );
    }

    /**
     * @param  Builder<Post>  $query
     * @param  Collection<int, string>|null  $shareSortActorIds
     */
    private function paginateKeyset(
        Builder $query,
        int $perPage,
        ?FeedCursor $cursor,
        bool $useShareSortCursor,
        ?Request $request = null,
        ?Collection $shareSortActorIds = null,
    ): FeedPage {
        if ($useShareSortCursor) {
            $this->applySharedSortCursor($query, $cursor, $shareSortActorIds ?? collect());
        } else {
            $this->applyPublishedAtCursor($query, $cursor);
        }

        /** @var Collection<int, Post> $items */
        $items = $query->limit($perPage + 1)->get();

        $hasMore = $items->count() > $perPage;

        if ($hasMore) {
            $items = $items->take($perPage)->values();
        }

        $nextPageUrl = null;

        if ($hasMore && $items->isNotEmpty()) {
            $request ??= request();
            $nextCursor = FeedCursor::fromPost($items->last(), $useShareSortCursor);
            $queryParams = array_merge($request->except(['cursor', 'page']), [
                'cursor' => $nextCursor->encode(),
            ]);
            $nextPageUrl = $request->url().'?'.http_build_query($queryParams);
        }

        return new FeedPage($items, $nextPageUrl);
    }

    /**
     * @param  Builder<Post>  $query
     */
    private function applyPublishedAtCursor(Builder $query, ?FeedCursor $cursor): void
    {
        if ($cursor === null) {
            return;
        }

        $query->where(function (Builder $builder) use ($cursor): void {
            $builder->where('posts.published_at', '<', $cursor->sortAt)
                ->orWhere(function (Builder $sameInstant) use ($cursor): void {
                    $sameInstant->where('posts.published_at', '=', $cursor->sortAt)
                        ->where('posts.'.self::TIEBREAKER_COLUMN, '<', $cursor->postId);
                });
        });
    }

    /**
     * @param  Builder<Post>  $query
     * @param  Collection<int, string>  $sharerIds
     */
    private function applySharedSortCursor(Builder $query, ?FeedCursor $cursor, Collection $sharerIds): void
    {
        if ($cursor === null) {
            return;
        }

        // Non usare l'alias SELECT "shared_at" in WHERE: MySQL/MariaDB lo
        // rifiutano ("Unknown column"). Ripetiamo la stessa subquery correlata
        // usata in {@see withShareMetadata()} dentro coalesce(...).
        $sharedAt = $this->sharedAtSubquery($sharerIds);
        $sortExpr = 'coalesce(('.$sharedAt->toSql().'), posts.published_at)';
        $sortBindings = $sharedAt->getBindings();

        $query->where(function (Builder $builder) use ($cursor, $sortExpr, $sortBindings): void {
            $builder->whereRaw($sortExpr.' < ?', [...$sortBindings, $cursor->sortAt])
                ->orWhere(function (Builder $sameInstant) use ($cursor, $sortExpr, $sortBindings): void {
                    $sameInstant->whereRaw($sortExpr.' = ?', [...$sortBindings, $cursor->sortAt])
                        ->where('posts.'.self::TIEBREAKER_COLUMN, '<', $cursor->postId);
                });
        });
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

        return $query
            ->addSelect(['shared_by_actor_id' => $announcerId])
            ->addSelect(['shared_at' => $this->sharedAtSubquery($sharerIds)]);
    }

    /**
     * @param  Collection<int, string>  $sharerIds
     * @return \Illuminate\Database\Query\Builder
     */
    private function sharedAtSubquery(Collection $sharerIds): \Illuminate\Database\Query\Builder
    {
        return DB::table('announces')
            ->select('created_at')
            ->whereColumn('post_id', 'posts.id')
            ->whereIn('actor_id', $sharerIds)
            ->orderByDesc('created_at')
            ->limit(1);
    }
}
