<?php

namespace App\Application\Queries;

use App\Application\Services\AnnounceManager;
use App\Domain\Communities\Community;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Federation\Inbox\InboxActivityProcessor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
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

    // Limite di lavoro del tentativo cronologico, non limite del feed:
    // se i candidati non bastano, si usa la ricerca completa per autore.
    // ponytail: soglia euristica fissa; ritararla solo se i benchmark mostrano fallback frequenti.
    private const FOLLOWED_POST_PROBE_LIMIT = 256;

    public function forActor(Actor $viewer, ?FeedCursor $cursor = null, int $perPage = 0): FeedPage
    {
        $perPage = $perPage > 0 ? $perPage : (int) config('openbook.feed.per_page');

        $isFollowedByViewer = function ($query, string $actorColumn) use ($viewer): void {
            $query->selectRaw('1')
                ->from('follows as feed_follows')
                ->where('feed_follows.follower_id', $viewer->id)
                ->where('feed_follows.status', 'accepted')
                ->whereColumn('feed_follows.following_id', $actorColumn);
        };

        $isRelevantAnnounce = function ($query, string $announceAlias) use ($viewer, $isFollowedByViewer): void {
            $query->where($announceAlias.'.actor_id', $viewer->id)
                ->orWhereExists(fn ($following) => $isFollowedByViewer($following, $announceAlias.'.actor_id'));
        };

        $onlyLatestRelevantAnnounce = function ($query, string $announceAlias) use ($isRelevantAnnounce): void {
            $query->whereNotExists(function ($newerAnnounce) use ($announceAlias, $isRelevantAnnounce): void {
                $newerAnnounce->selectRaw('1')
                    ->from('announces as newer_announces')
                    ->whereColumn('newer_announces.post_id', $announceAlias.'.post_id')
                    ->where(function ($newerEvent) use ($announceAlias): void {
                        $newerEvent->whereColumn('newer_announces.created_at', '>', $announceAlias.'.created_at')
                            ->orWhere(function ($sameInstant) use ($announceAlias): void {
                                $sameInstant->whereColumn('newer_announces.created_at', '=', $announceAlias.'.created_at')
                                    ->whereColumn('newer_announces.id', '>', $announceAlias.'.id');
                            });
                    })
                    ->where(function ($relevant) use ($isRelevantAnnounce): void {
                        $isRelevantAnnounce($relevant, 'newer_announces');
                    });
            });
        };

        $applyCandidateCursor = function ($query, string $timelineColumn, string $postIdColumn) use ($cursor): void {
            if ($cursor === null) {
                return;
            }

            $query->where(function ($candidate) use ($cursor, $timelineColumn, $postIdColumn): void {
                $candidate->where($timelineColumn, '<', $cursor->sortAt)
                    ->orWhere(function ($sameInstant) use ($cursor, $timelineColumn, $postIdColumn): void {
                        $sameInstant->where($timelineColumn, '=', $cursor->sortAt)
                            ->where($postIdColumn, '<', $cursor->postId);
                    });
            });
        };

        // Ogni stream contiene post distinti, ammissibili e ordinati come
        // il risultato finale: i primi K dell'unione sono quindi sempre
        // nell'unione dei primi K di ciascuno stream, anche se si sovrappongono.
        $candidateLimit = $perPage + 1;
        $eligiblePosts = Post::query()
            ->excludingPrivateMessages()
            ->where('posts.status', Post::STATUS_PUBLISHED)
            ->visibleTo($viewer);
        $postSource = DB::query()->fromSub(
            (clone $eligiblePosts)->select('posts.id', 'posts.actor_id', 'posts.community_id', 'posts.published_at'),
            'posts',
        );
        // Lookup scalare sulla PK: verifica il post dell'evento corrente
        // senza trasformare lo stream Announce in una scansione dei post.
        $eligibleAnnouncedPost = (clone $eligiblePosts)->selectRaw('1')
            ->whereColumn('posts.id', 'announces.post_id')->limit(1);

        // Un post con una condivisione rilevante deve essere ordinato dalla
        // condivisione, anche quando il post originale e' piu' recente.
        // Per questo gli eventi "post" escludono esplicitamente tali post.
        $ownPostEvents = (clone $postSource)
            ->select('posts.id as post_id', 'posts.published_at as timeline_at')
            ->selectRaw('null as shared_by_actor_id, null as shared_at, posts.id as event_id')
            ->where('posts.actor_id', $viewer->id)
            ->whereNotExists(function ($announce) use ($isRelevantAnnounce): void {
                $announce->selectRaw('1')
                    ->from('announces as relevant_announces')
                    ->whereColumn('relevant_announces.post_id', 'posts.id')
                    ->where(function ($relevant) use ($isRelevantAnnounce): void {
                        $isRelevantAnnounce($relevant, 'relevant_announces');
                    });
            });
        $applyCandidateCursor($ownPostEvents, 'posts.published_at', 'posts.id');
        $ownPostEvents->orderByDesc('posts.published_at')->orderByDesc('posts.id')->limit($candidateLimit);

        $followedPostEvents = (clone $postSource)
            ->join('follows as source_follows', function ($join) use ($viewer): void {
                $join->on('source_follows.following_id', 'posts.actor_id')
                    ->where('source_follows.follower_id', $viewer->id)
                    ->where('source_follows.status', 'accepted');
            })
            ->select('posts.id as post_id', 'posts.published_at as timeline_at')
            ->selectRaw('null as shared_by_actor_id, null as shared_at, posts.id as event_id')
            ->whereNotExists(function ($announce) use ($isRelevantAnnounce): void {
                $announce->selectRaw('1')
                    ->from('announces as relevant_announces')
                    ->whereColumn('relevant_announces.post_id', 'posts.id')
                    ->where(function ($relevant) use ($isRelevantAnnounce): void {
                        $isRelevantAnnounce($relevant, 'relevant_announces');
                    });
            });
        $applyCandidateCursor($followedPostEvents, 'posts.published_at', 'posts.id');
        $followedPostEvents->orderByDesc('posts.published_at')->orderByDesc('posts.id')->limit($candidateLimit);

        $recentPosts = DB::table('posts')->select('posts.id')
            ->where('posts.status', Post::STATUS_PUBLISHED)
            ->whereNull('posts.conversation_id');
        $applyCandidateCursor($recentPosts, 'posts.published_at', 'posts.id');
        $recentPosts->orderByDesc('posts.published_at')->orderByDesc('posts.id')
            ->limit(self::FOLLOWED_POST_PROBE_LIMIT);

        $recentFollowedPosts = (clone $followedPostEvents)
            ->joinSub($recentPosts, 'recent_posts', fn ($join) => $join->on('recent_posts.id', 'posts.id'));
        $recentCount = DB::query()->fromSub(clone $recentFollowedPosts, 'recent_followed_posts')->selectRaw('count(*)');
        $fallbackPosts = (clone $followedPostEvents)->where($recentCount, '<', $candidateLimit);

        // Tutto nella stessa SELECT: il conteggio e il fallback vedono lo
        // stesso snapshot. UNION elimina i candidati recenti ripetuti dal
        // fallback, che viene eseguito solo se non abbiamo gia' K post validi.
        $followedPostEvents = DB::query()->fromSub(
            $recentFollowedPosts->union($fallbackPosts),
            'followed_candidates',
        )->select('post_id', 'timeline_at', 'shared_by_actor_id', 'shared_at', 'event_id')
            ->orderByDesc('timeline_at')->orderByDesc('post_id')->limit($candidateLimit);

        $communityPostEvents = (clone $postSource)
            ->join('communities as source_communities', 'source_communities.id', '=', 'posts.community_id')
            ->join('follows as source_follows', function ($join) use ($viewer): void {
                $join->on('source_follows.following_id', 'source_communities.actor_id')
                    ->where('source_follows.follower_id', $viewer->id)
                    ->where('source_follows.status', 'accepted');
            })
            ->select('posts.id as post_id', 'posts.published_at as timeline_at')
            ->selectRaw('null as shared_by_actor_id, null as shared_at, posts.id as event_id')
            ->whereNotExists(function ($announce) use ($isRelevantAnnounce): void {
                $announce->selectRaw('1')
                    ->from('announces as relevant_announces')
                    ->whereColumn('relevant_announces.post_id', 'posts.id')
                    ->where(function ($relevant) use ($isRelevantAnnounce): void {
                        $isRelevantAnnounce($relevant, 'relevant_announces');
                    });
            });
        $applyCandidateCursor($communityPostEvents, 'posts.published_at', 'posts.id');
        $communityPostEvents->orderByDesc('posts.published_at')->orderByDesc('posts.id')->limit($candidateLimit);

        $ownAnnounceEvents = DB::table('announces as announces')
            ->where(clone $eligibleAnnouncedPost, '=', 1)
            ->select('announces.post_id', 'announces.created_at as timeline_at')
            ->selectRaw('announces.actor_id as shared_by_actor_id, announces.created_at as shared_at, announces.id as event_id')
            ->where('announces.actor_id', $viewer->id);
        $onlyLatestRelevantAnnounce($ownAnnounceEvents, 'announces');
        $applyCandidateCursor($ownAnnounceEvents, 'announces.created_at', 'announces.post_id');
        $ownAnnounceEvents->orderByDesc('announces.created_at')->orderByDesc('announces.post_id')->limit($candidateLimit);

        $followedAnnounceEvents = DB::table('announces as announces')
            ->where(clone $eligibleAnnouncedPost, '=', 1)
            ->join('follows as source_follows', function ($join) use ($viewer): void {
                $join->on('source_follows.following_id', 'announces.actor_id')
                    ->where('source_follows.follower_id', $viewer->id)
                    ->where('source_follows.status', 'accepted');
            })
            ->select('announces.post_id', 'announces.created_at as timeline_at')
            ->selectRaw('announces.actor_id as shared_by_actor_id, announces.created_at as shared_at, announces.id as event_id');
        $onlyLatestRelevantAnnounce($followedAnnounceEvents, 'announces');
        $applyCandidateCursor($followedAnnounceEvents, 'announces.created_at', 'announces.post_id');
        $followedAnnounceEvents->orderByDesc('announces.created_at')->orderByDesc('announces.post_id')->limit($candidateLimit);

        $rankedEvents = DB::query()
            ->fromSub(
                $ownPostEvents
                    ->unionAll($followedPostEvents)
                    ->unionAll($communityPostEvents)
                    ->unionAll($ownAnnounceEvents)
                    ->unionAll($followedAnnounceEvents),
                'feed_events',
            )
            ->select('feed_events.post_id', 'feed_events.timeline_at', 'feed_events.shared_by_actor_id', 'feed_events.shared_at')
            ->selectRaw('row_number() over (partition by feed_events.post_id order by feed_events.timeline_at desc, feed_events.event_id desc) as event_rank');

        $query = Post::query()
            ->with(Post::CARD_RELATIONS)
            ->joinSub($rankedEvents, 'feed_events', fn ($join) => $join->on('feed_events.post_id', 'posts.id'))
            ->select('posts.*', 'feed_events.shared_by_actor_id', 'feed_events.shared_at')
            ->where('feed_events.event_rank', 1);

        $query = $query
            ->orderByDesc('feed_events.timeline_at')
            ->orderByDesc('posts.'.self::TIEBREAKER_COLUMN);

        $page = $this->paginateKeyset(
            $query,
            $perPage,
            $cursor,
            useShareSortCursor: true,
            sharedSortColumn: 'feed_events.timeline_at',
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
     * Accetta anche una Relation (es. BelongsToMany da Hashtag::posts()).
     *
     * @param  Builder<Post>|Relation<*, Post, *>  $query
     */
    public function paginatePublishedQuery(Builder|Relation $query, ?FeedCursor $cursor = null, int $perPage = 0): FeedPage
    {
        $perPage = $perPage > 0 ? $perPage : (int) config('openbook.feed.per_page');

        $builder = $query instanceof Relation ? $query->getQuery() : $query;

        return $this->paginateKeyset(
            $builder->orderByDesc('published_at')->orderByDesc('posts.'.self::TIEBREAKER_COLUMN),
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
        ?string $sharedSortColumn = null,
    ): FeedPage {
        if ($useShareSortCursor) {
            if ($sharedSortColumn !== null) {
                $this->applyTimelineCursor($query, $cursor, $sharedSortColumn);
            } else {
                $this->applySharedSortCursor($query, $cursor, $shareSortActorIds ?? collect());
            }
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
     */
    private function applyTimelineCursor(Builder $query, ?FeedCursor $cursor, string $timelineColumn): void
    {
        if ($cursor === null) {
            return;
        }

        $query->where(function (Builder $builder) use ($cursor, $timelineColumn): void {
            $builder->where($timelineColumn, '<', $cursor->sortAt)
                ->orWhere(function (Builder $sameInstant) use ($cursor, $timelineColumn): void {
                    $sameInstant->where($timelineColumn, '=', $cursor->sortAt)
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
