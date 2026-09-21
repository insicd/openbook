<?php

namespace App\Federation\Outbox;

use App\Application\Queries\FeedQuery;
use App\Application\Services\AnnounceManager;
use App\Domain\Events\Event;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Federation\Actors\RemoteActorResolver;
use App\Federation\Fetch\FederationFetchSigner;
use App\Federation\Inbox\InboxActivityProcessor;
use App\Federation\Inbox\RemoteEventIngester;
use App\Federation\Inbox\RemoteEventObject;
use App\Federation\Inbox\RemoteNoteDocumentFetcher;
use App\Federation\Inbox\RemoteNoteUpserter;
use App\Federation\Inbox\RemotePostObject;
use App\Federation\Support\ActivityPubTimestamp;
use App\Infrastructure\Security\Http\SafeHttpClient;
use App\Infrastructure\Security\Http\SsrfViolationException;
use Illuminate\Support\Carbon;

/**
 * Recupera i contenuti pubblici piu' recenti dichiarati da un Actor remoto,
 * per popolare la sua pagina profilo (`ActorProfileController`) con qualcosa
 * di piu' della sola cache passiva costruita dall'inbox
 * ({@see InboxActivityProcessor::isRelevant()}, che
 * per costruzione ignora i post di un autore non ancora seguito da nessun
 * Actor locale). A differenza della sezione "Mondo" (vedi
 * {@see FeedQuery::world()}), qui la richiesta e'
 * esplicita: chi visita il profilo di un Actor specifico ha gia' espresso
 * interesse per i *suoi* contenuti, quindi il vincolo di rilevanza
 * dell'inbox non si applica.
 *
 * Per i post recupera solo la prima pagina dell'outbox (non l'intera
 * cronologia) e solo gli originali, non le risposte. Se l'outbox e' uno stub (Pixelfed
 * espone spesso solo totalItems senza first/orderedItems), ricade sul feed
 * Atom `{actor}.atom` tramite {@see RemoteAtomFeedBackfill}. Se l'outbox e'
 * vuoto in stile Wafrn (`200` senza collection), ricade sull'API pubblica
 * `/api/v2/blog` tramite {@see RemoteWafrnBlogBackfill}. Threads (Meta) non
 * espone affatto i post nell'outbox: in quel caso restano solo i contenuti
 * gia' ricevuti in inbox dopo un Follow. Per gli Event preferisce l'eventuale
 * collection dedicata dichiarata dall'Actor e usa l'outbox come fallback.
 */
final class RemoteOutboxFetcher
{
    private const MAX_ITEMS = 20;

    public function __construct(
        private readonly SafeHttpClient $httpClient,
        private readonly RemoteNoteUpserter $noteUpserter,
        private readonly RemoteEventIngester $eventIngester,
        private readonly RemoteNoteDocumentFetcher $noteDocumentFetcher,
        private readonly RemoteAtomFeedBackfill $atomFeedBackfill,
        private readonly RemoteWafrnBlogBackfill $wafrnBlogBackfill,
        private readonly RemoteActorResolver $remoteActorResolver,
        private readonly AnnounceManager $announceManager,
        private readonly FederationFetchSigner $fetchSigner,
    ) {}

    public function fetchRecentContent(Actor $actor): void
    {
        if ($actor->isLocal()) {
            return;
        }

        $ttlHours = max(1, (int) config('openbook.federation.posts_cache_ttl_hours', 6));
        $postsWithinTtl = $actor->posts_fetched_at !== null
            && $actor->posts_fetched_at->gt(Carbon::now()->subHours($ttlHours));
        $eventsWithinTtl = $actor->events_fetched_at !== null
            && $actor->events_fetched_at->gt(Carbon::now()->subHours($ttlHours));
        $fetchPosts = ! $postsWithinTtl || (! $this->hasCachedPosts($actor) && ! $this->hasCachedEvents($actor));
        $fetchEvents = ! $eventsWithinTtl;

        // Se la cache e' fresca ma non abbiamo ancora alcun contenuto,
        // ritenta: tipico dopo un outbox Pixelfed stub prima del fallback Atom.
        if (! $fetchPosts && ! $fetchEvents) {
            return;
        }

        // Aggiornato subito, prima ancora di tentare la richiesta: un
        // server remoto irraggiungibile non deve rallentare ogni successivo
        // caricamento della pagina fino alla scadenza naturale della cache.
        $timestamps = [];

        if ($fetchPosts) {
            $timestamps['posts_fetched_at'] = now();
        }

        if ($fetchEvents) {
            $timestamps['events_fetched_at'] = now();
        }

        $actor->forceFill($timestamps)->saveQuietly();

        $actor->loadMissing('endpoints');

        // Gli Actor Group Mobilizon dichiarano spesso una collection Event
        // dedicata. Gli Actor gia' in cache prima dell'introduzione del campo
        // devono essere aggiornati una volta per poterla scoprire.
        if ($fetchEvents && $actor->isGroup() && blank($actor->endpoints?->events)) {
            $actor = $this->remoteActorResolver->refresh($actor) ?? $actor;
            $actor->loadMissing('endpoints');
        }

        $eventsUrl = $actor->endpoints?->events;

        if ($fetchEvents && filled($eventsUrl)) {
            foreach ($this->fetchRecentEventItems($eventsUrl, $this->fetchSigner->resolve()) as $item) {
                $this->ingestEventItem($item, $actor);
            }
        }

        $outboxUrl = $actor->endpoints?->outbox;
        $signingActor = $this->fetchSigner->resolve();

        $fetchEventsFromOutbox = $fetchEvents && blank($eventsUrl);
        $items = ($fetchPosts || $fetchEventsFromOutbox) && filled($outboxUrl)
            ? $this->fetchItems($outboxUrl, $signingActor)
            : [];

        foreach ($items as $item) {
            if ($fetchPosts) {
                $this->ingestItem($item, $actor);
            }

            if ($fetchEventsFromOutbox) {
                $this->ingestEventItem($item, $actor);
            }
        }

        if ($fetchPosts && $items === [] && ! $this->hasCachedPosts($actor)) {
            $this->ingestBackfillNotes(
                $this->atomFeedBackfill->fetchNotes($actor, $signingActor, self::MAX_ITEMS),
                $actor,
            );
        }

        if ($fetchPosts && $items === [] && ! $this->hasCachedPosts($actor)) {
            $this->ingestBackfillNotes(
                $this->wafrnBlogBackfill->fetchNotes($actor, $signingActor, self::MAX_ITEMS),
                $actor,
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $notes
     */
    private function ingestBackfillNotes(array $notes, Actor $actor): void
    {
        foreach ($notes as $note) {
            if (($note['inReplyTo'] ?? null) !== null) {
                continue;
            }

            if (! RemotePostObject::authorMatches($note['attributedTo'] ?? null, $actor->uri)) {
                continue;
            }

            $this->upsertPublicPost($note, $actor);
        }
    }

    private function hasCachedPosts(Actor $actor): bool
    {
        return Post::query()
            ->where('actor_id', $actor->id)
            ->where('status', Post::STATUS_PUBLISHED)
            ->exists();
    }

    private function hasCachedEvents(Actor $actor): bool
    {
        return Event::query()
            ->where(function ($query) use ($actor): void {
                $query->where('actor_id', $actor->id)
                    ->orWhereHas('attributions', fn ($attributions) => $attributions->whereKey($actor->id));
            })
            ->exists();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchItems(string $outboxUrl, ?Actor $signingActor): array
    {
        $document = $this->fetchDocument($outboxUrl, $signingActor);

        if ($document === null) {
            return [];
        }

        $items = $this->orderedItemsOf($document);

        if ($items !== null) {
            return $items;
        }

        $first = $document['first'] ?? null;

        if (is_array($first)) {
            return $this->orderedItemsOf($first) ?? [];
        }

        if (is_string($first) && $first !== '') {
            return $this->orderedItemsOf($this->fetchDocument($first, $signingActor) ?? []) ?? [];
        }

        return [];
    }

    /**
     * Mobilizon ordina alcune collection Event dalla voce piu' vecchia e non
     * pubblica `last`. In quel caso calcoliamo l'ultima pagina soltanto quando
     * totalItems, cardinalita' della prima pagina e `next?page=N` concordano.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchRecentEventItems(string $collectionUrl, ?Actor $signingActor): array
    {
        $collection = $this->fetchDocument($collectionUrl, $signingActor);

        if ($collection === null) {
            return [];
        }

        $first = $collection['first'] ?? $collection;
        $firstPage = is_array($first)
            ? $first
            : (is_string($first) && $first !== '' ? $this->fetchDocument($first, $signingActor) : null);

        if (! is_array($firstPage)) {
            return [];
        }

        $firstItems = $this->orderedItemsOf($firstPage) ?? [];
        $recentItems = $firstItems;
        $lastPageUrl = $this->lastPageUrl($collectionUrl, $collection, $firstPage, count($firstItems));

        if ($lastPageUrl !== null) {
            $lastPage = $this->fetchDocument($lastPageUrl, $signingActor);

            if ($this->belongsToCollection($lastPage, $collectionUrl)) {
                $lastItems = $this->orderedItemsOf($lastPage) ?? [];

                if ($this->latestTimestamp($lastItems) >= $this->latestTimestamp($firstItems)) {
                    $recentItems = $lastItems;

                    $previousPageUrl = $this->adjacentPageUrl($lastPageUrl, -1);

                    if (count($recentItems) < self::MAX_ITEMS && $previousPageUrl !== null) {
                        $previousPage = $this->fetchDocument($previousPageUrl, $signingActor);

                        if ($this->belongsToCollection($previousPage, $collectionUrl)) {
                            $recentItems = array_merge(
                                $this->orderedItemsOf($previousPage) ?? [],
                                $recentItems,
                            );
                        }
                    }
                }
            }
        }

        return array_slice($recentItems, -self::MAX_ITEMS);
    }

    /**
     * @param  array<string, mixed>  $collection
     * @param  array<string, mixed>  $firstPage
     */
    private function lastPageUrl(string $collectionUrl, array $collection, array $firstPage, int $pageSize): ?string
    {
        $last = $collection['last'] ?? null;

        if (is_string($last) && $last !== '' && $this->isCollectionPageUrl($last, $collectionUrl)) {
            return $last;
        }

        if (is_array($last)
            && is_string($last['id'] ?? null)
            && $this->isCollectionPageUrl($last['id'], $collectionUrl)) {
            return $last['id'];
        }

        $totalItems = filter_var($collection['totalItems'] ?? null, FILTER_VALIDATE_INT);
        $next = $firstPage['next'] ?? null;

        if (! is_int($totalItems)
            || $totalItems <= $pageSize
            || $pageSize < 1
            || ! is_string($next)
            || ! $this->isCollectionPageUrl($next, $collectionUrl)) {
            return null;
        }

        $nextPage = $this->pageNumber($next);
        $currentPage = $this->pageNumber(is_string($firstPage['id'] ?? null) ? $firstPage['id'] : '');

        if ($nextPage === null || $currentPage === null || $nextPage !== $currentPage + 1) {
            return null;
        }

        $lastPage = (int) ceil($totalItems / $pageSize);

        if ($lastPage <= $currentPage || $lastPage > 100_000) {
            return null;
        }

        return $this->withPageNumber($next, $lastPage);
    }

    private function isCollectionPageUrl(string $pageUrl, string $collectionUrl): bool
    {
        $page = parse_url($pageUrl);
        $collection = parse_url($collectionUrl);

        if (! is_array($page) || ! is_array($collection)) {
            return false;
        }

        return strtolower((string) ($page['scheme'] ?? '')) === strtolower((string) ($collection['scheme'] ?? ''))
            && strtolower((string) ($page['host'] ?? '')) === strtolower((string) ($collection['host'] ?? ''))
            && ($page['port'] ?? null) === ($collection['port'] ?? null)
            && rtrim((string) ($page['path'] ?? ''), '/') === rtrim((string) ($collection['path'] ?? ''), '/');
    }

    private function adjacentPageUrl(string $url, int $offset): ?string
    {
        $page = $this->pageNumber($url);

        return $page !== null && $page + $offset > 0
            ? $this->withPageNumber($url, $page + $offset)
            : null;
    }

    private function pageNumber(string $url): ?int
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $page = filter_var($query['page'] ?? null, FILTER_VALIDATE_INT);

        return is_int($page) && $page > 0 ? $page : null;
    }

    private function withPageNumber(string $url, int $page): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        $query['page'] = $page;
        $authority = $parts['scheme'].'://'.($parts['user'] ?? '');

        if (isset($parts['pass'])) {
            $authority .= ':'.$parts['pass'];
        }

        if (isset($parts['user'])) {
            $authority .= '@';
        }

        $authority .= $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return $authority.($parts['path'] ?? '').'?'.http_build_query($query);
    }

    /** @param array<string, mixed>|null $page */
    private function belongsToCollection(?array $page, string $collectionUrl): bool
    {
        $partOf = is_array($page) ? ($page['partOf'] ?? null) : null;

        return is_string($partOf) && rtrim($partOf, '/') === rtrim($collectionUrl, '/');
    }

    /** @param list<array<string, mixed>> $items */
    private function latestTimestamp(array $items): int
    {
        $latest = 0;

        foreach ($items as $item) {
            $event = RemoteEventObject::unwrap($item);
            $value = $event['published'] ?? $event['updated'] ?? $event['startTime'] ?? null;

            if (is_string($value)) {
                $latest = max($latest, strtotime($value) ?: 0);
            }
        }

        return $latest;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<array<string, mixed>>|null
     */
    private function orderedItemsOf(array $document): ?array
    {
        $items = $document['orderedItems'] ?? null;

        if (! is_array($items)) {
            return null;
        }

        return array_slice(array_values(array_filter($items, 'is_array')), 0, self::MAX_ITEMS);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchDocument(string $url, ?Actor $signingActor): ?array
    {
        try {
            $response = $this->httpClient->get($url, ['Accept' => 'application/activity+json'], $signingActor);
        } catch (SsrfViolationException) {
            return null;
        } catch (\Throwable) {
            return null;
        }

        return $response->successful() ? $response->json() : null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function ingestItem(array $item, Actor $actor): void
    {
        if ($actor->isGroup() && ($item['type'] ?? null) === 'Announce') {
            $this->ingestGroupAnnounce($item, $actor);

            return;
        }

        $note = RemotePostObject::unwrap($item);

        if ($note === null) {
            return;
        }

        // Un outbox Person deve contenere solo contenuto del suo stesso Actor
        // (PeerTube puo' elencare anche il canale Group in attributedTo).
        if (! RemotePostObject::authorMatches($note['attributedTo'] ?? null, $actor->uri)) {
            return;
        }

        if (($note['inReplyTo'] ?? null) !== null) {
            return;
        }

        $this->upsertPublicPost($note, $actor);
    }

    /** @param array<string, mixed> $item */
    private function ingestEventItem(array $item, Actor $collectionActor): void
    {
        $event = RemoteEventObject::unwrap($item);

        if ($event === null || ! in_array(RemoteEventObject::visibility($event), [Event::VISIBILITY_PUBLIC, Event::VISIBILITY_UNLISTED], true)) {
            return;
        }

        $activityActorUri = is_string($item['actor'] ?? null)
            ? $item['actor']
            : RemoteEventObject::creatorUri($event);

        if ($activityActorUri === null) {
            return;
        }

        $activityActor = $activityActorUri === $collectionActor->uri
            ? $collectionActor
            : $this->remoteActorResolver->resolveByUri($activityActorUri);

        if ($activityActor === null) {
            return;
        }

        $this->eventIngester->ingest($event, $activityActor, 'Create');
    }

    /**
     * Outbox di un Group (FEP-1b12 / Lemmy): Announce di Note o Page altrui,
     * spesso annidate come Announce → Create → Page.
     *
     * @param  array<string, mixed>  $item
     */
    private function ingestGroupAnnounce(array $item, Actor $group): void
    {
        $object = $item['object'] ?? null;
        $note = null;

        if (is_array($object)) {
            $note = RemotePostObject::unwrap($object);
        }

        if ($note === null && is_string($object) && $object !== '') {
            $note = $this->noteDocumentFetcher->fetch($object);
        } elseif ($note === null && is_array($object) && is_string($object['id'] ?? null) && ! RemotePostObject::isPostable($object['type'] ?? null)) {
            // Create senza object inline, o riferimento opaco: fetch per id.
            $note = $this->noteDocumentFetcher->fetch($object['id']);
        }

        if ($note === null || ! RemotePostObject::isPostable($note['type'] ?? null) || ($note['inReplyTo'] ?? null) !== null) {
            return;
        }

        $authorUri = RemotePostObject::primaryAuthorUri($note['attributedTo'] ?? null);

        if ($authorUri === null) {
            return;
        }

        $author = $this->remoteActorResolver->resolveByUri($authorUri);

        if ($author === null) {
            return;
        }

        $post = $this->upsertPublicPost($note, $author);

        if ($post !== null) {
            $this->announceManager->announce(
                $group,
                $post,
                notify: false,
                occurredAt: $post->published_at,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $note
     */
    private function upsertPublicPost(array $note, Actor $author): ?Post
    {
        $noteUri = $note['id'] ?? null;

        if (! is_string($noteUri) || $noteUri === '') {
            return null;
        }

        $visibility = $this->noteUpserter->visibilityFromAudience($note);

        if (! in_array($visibility, [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_UNLISTED], true)) {
            return null;
        }

        $body = RemotePostObject::body($note);
        $publishedAt = ActivityPubTimestamp::parse(
            isset($note['published']) && is_string($note['published']) ? $note['published'] : null,
        );

        return $this->noteUpserter->upsertPost($note, $noteUri, $author, $body, $publishedAt, notifyMentions: false);
    }
}
