<?php

namespace App\Federation\Outbox;

use App\Application\Queries\FeedQuery;
use App\Application\Services\AnnounceManager;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Federation\Actors\RemoteActorResolver;
use App\Federation\Fetch\FederationFetchSigner;
use App\Federation\Inbox\InboxActivityProcessor;
use App\Federation\Inbox\RemoteNoteDocumentFetcher;
use App\Federation\Inbox\RemoteNoteUpserter;
use App\Federation\Inbox\RemotePostObject;
use App\Infrastructure\Security\Http\SafeHttpClient;
use App\Infrastructure\Security\Http\SsrfViolationException;
use Illuminate\Support\Carbon;

/**
 * Recupera i post pubblici piu' recenti dall'outbox reale di un Actor
 * remoto, per popolare la sua pagina profilo (`ActorProfileController`) con
 * qualcosa di piu' della sola cache passiva costruita dall'inbox
 * ({@see InboxActivityProcessor::isRelevant()}, che
 * per costruzione ignora i post di un autore non ancora seguito da nessun
 * Actor locale). A differenza della sezione "Mondo" (vedi
 * {@see FeedQuery::world()}), qui la richiesta e'
 * esplicita: chi visita il profilo di un Actor specifico ha gia' espresso
 * interesse per i *suoi* contenuti, quindi il vincolo di rilevanza
 * dell'inbox non si applica.
 *
 * Recupera solo la prima pagina dell'outbox (non l'intera cronologia) e
 * solo i post originali, non le risposte (il cui post padre e' quasi
 * sempre sconosciuto localmente e non potrebbe comunque essere agganciato):
 * l'obiettivo e' dare un'idea di chi e' questo Actor, non replicarne
 * l'intera timeline.
 */
final class RemoteOutboxFetcher
{
    private const MAX_ITEMS = 20;

    public function __construct(
        private readonly SafeHttpClient $httpClient,
        private readonly RemoteNoteUpserter $noteUpserter,
        private readonly RemoteNoteDocumentFetcher $noteDocumentFetcher,
        private readonly RemoteActorResolver $remoteActorResolver,
        private readonly AnnounceManager $announceManager,
        private readonly FederationFetchSigner $fetchSigner,
    ) {}

    public function fetchRecentPosts(Actor $actor): void
    {
        if ($actor->isLocal()) {
            return;
        }

        $ttlHours = (int) config('openbook.federation.posts_cache_ttl_hours', 6);

        if ($actor->posts_fetched_at !== null && $actor->posts_fetched_at->gt(Carbon::now()->subHours($ttlHours))) {
            return;
        }

        // Aggiornato subito, prima ancora di tentare la richiesta: un
        // server remoto irraggiungibile non deve rallentare ogni successivo
        // caricamento della pagina fino alla scadenza naturale della cache.
        $actor->forceFill(['posts_fetched_at' => now()])->saveQuietly();

        $actor->loadMissing('endpoints');
        $outboxUrl = $actor->endpoints?->outbox;

        if (blank($outboxUrl)) {
            return;
        }

        $signingActor = $this->fetchSigner->resolve();
        $items = $this->fetchItems($outboxUrl, $signingActor);

        foreach ($items as $item) {
            $this->ingestItem($item, $actor);
        }
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
        $publishedAt = isset($note['published']) && is_string($note['published'])
            ? Carbon::parse($note['published'])
            : now();

        return $this->noteUpserter->upsertPost($note, $noteUri, $author, $body, $publishedAt, notifyMentions: false);
    }
}
