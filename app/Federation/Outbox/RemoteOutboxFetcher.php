<?php

namespace App\Federation\Outbox;

use App\Application\Queries\FeedQuery;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Federation\Fetch\FederationFetchSigner;
use App\Federation\Inbox\InboxActivityProcessor;
use App\Federation\Inbox\RemoteContentSanitizer;
use App\Federation\Inbox\RemoteNoteUpserter;
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
        $note = $item;

        if (($item['type'] ?? null) === 'Create' && is_array($item['object'] ?? null)) {
            $note = $item['object'];
        }

        if (($note['type'] ?? null) !== 'Note') {
            return;
        }

        // Un outbox deve contenere solo contenuto del suo stesso Actor:
        // scarta qualunque cosa dichiari un autore diverso, per non
        // spacciare contenuto altrui per conto di chi stiamo visitando.
        if (($note['attributedTo'] ?? null) !== $actor->uri) {
            return;
        }

        // Solo post originali: una risposta il cui padre e' quasi sempre
        // sconosciuto a questa istanza non potrebbe comunque essere
        // agganciata a nulla (vedi InboxActivityProcessor::handleCreateOrUpdate).
        if (($note['inReplyTo'] ?? null) !== null) {
            return;
        }

        $noteUri = $note['id'] ?? null;

        if (! is_string($noteUri) || $noteUri === '') {
            return;
        }

        $visibility = $this->noteUpserter->visibilityFromAudience($note);

        // La pagina profilo di un Actor remoto non e' un canale privato:
        // vi si mostrano solo i contenuti che l'autore ha reso pubblici o
        // non elencati, mai post riservati ai follower o diretti.
        if (! in_array($visibility, [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_UNLISTED], true)) {
            return;
        }

        $body = RemoteContentSanitizer::toPlainText((string) ($note['content'] ?? ''));
        $publishedAt = isset($note['published']) && is_string($note['published'])
            ? Carbon::parse($note['published'])
            : now();

        // "notifyMentions: false": un post recuperato in blocco dall'outbox
        // non e' un evento "appena successo", quindi non deve generare
        // notifiche per menzioni magari vecchie di mesi.
        $this->noteUpserter->upsertPost($note, $noteUri, $actor, $body, $publishedAt, notifyMentions: false);
    }
}
