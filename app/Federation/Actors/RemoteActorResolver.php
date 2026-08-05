<?php

namespace App\Federation\Actors;

use App\Application\Services\DomainBlockManager;
use App\Federation\Fetch\FederationFetchSigner;
use App\Infrastructure\Security\Http\SafeHttpClient;
use App\Infrastructure\Security\Http\SsrfViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Recupera e mette in cache localmente un Actor remoto, riusando le tabelle
 * unificate "actors"/"actor_keys"/"actor_endpoints" gia' predisposte dal
 * Milestone 1 (distinte da quelle locali tramite "is_local = false"), invece
 * di introdurre una tabella di cache separata.
 *
 * Usato sia per verificare le firme HTTP in ingresso (serve la chiave
 * pubblica del mittente, Fase 3), sia per la ricerca federata di utenti e la
 * risoluzione degli attori citati da attivita' in ingresso/uscita (Fase 4).
 */
final class RemoteActorResolver
{
    public function __construct(
        private readonly SafeHttpClient $httpClient,
        private readonly DomainBlockManager $domainBlocks,
        private readonly FederationFetchSigner $fetchSigner,
    ) {}

    /**
     * @param  string  $keyId  es. "https://remoto.example/users/alice#main-key"
     */
    public function resolveByKeyId(string $keyId): ?Actor
    {
        $actorUri = explode('#', $keyId, 2)[0];
        $actor = $this->resolveByUri($actorUri);

        if ($actor !== null || str_contains($keyId, '#')) {
            return $actor;
        }

        return $this->resolveByStandaloneKeyDocument($keyId);
    }

    /**
     * Risolve un keyId che punta a una risorsa CryptographicKey autonoma,
     * anziche' all'Actor con un fragment "#main-key". Il documento della
     * chiave viene usato solo per individuare l'owner: la chiave PEM deve
     * comunque coincidere con quella pubblicata dal documento Actor.
     */
    private function resolveByStandaloneKeyDocument(string $keyId): ?Actor
    {
        if ($this->domainBlocks->isBlockedUrl($keyId)) {
            return null;
        }

        try {
            $response = $this->httpClient->get($keyId, [
                'Accept' => 'application/activity+json',
            ], $this->fetchSigner->resolve());
        } catch (SsrfViolationException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $document = $response->json();

        if (! $this->isValidStandaloneKeyDocument($document, $keyId)) {
            return null;
        }

        $ownerUri = (string) $document['owner'];
        $actor = $this->resolveByUri($ownerUri);

        if ($actor === null || $actor->key === null) {
            return null;
        }

        if (! hash_equals((string) $document['publicKeyPem'], (string) $actor->key->public_key)) {
            return null;
        }

        return $actor;
    }

    /**
     * @param  array<string, mixed>|null  $document
     */
    private function isValidStandaloneKeyDocument(?array $document, string $expectedKeyId): bool
    {
        if ($document === null
            || ! isset($document['id'], $document['type'], $document['owner'], $document['publicKeyPem'])
            || ! is_string($document['id'])
            || ! is_string($document['owner'])
            || ! is_string($document['publicKeyPem'])) {
            return false;
        }

        if ($document['id'] !== $expectedKeyId
            || $document['type'] !== 'CryptographicKey'
            || $document['owner'] === ''
            || $document['publicKeyPem'] === '') {
            return false;
        }

        $keyHost = parse_url($expectedKeyId, PHP_URL_HOST);
        $ownerHost = parse_url($document['owner'], PHP_URL_HOST);

        return is_string($keyHost)
            && is_string($ownerHost)
            && strcasecmp($keyHost, $ownerHost) === 0;
    }

    /**
     * Risolve un Actor a partire dal suo URI ActivityPub, usando la cache
     * locale finche' non e' scaduta ({@see config('openbook.federation.actor_cache_ttl_hours')}).
     */
    public function resolveByUri(string $actorUri): ?Actor
    {
        if ($this->domainBlocks->isBlockedUrl($actorUri)) {
            return null;
        }

        $existing = Actor::query()->where('uri', $actorUri)->with(['key', 'endpoints'])->first();

        // Un URI che punta a un Actor locale non e' un attore remoto
        // legittimo: non lo si recupera ne' lo si tratta come tale.
        if ($existing !== null && $existing->is_local) {
            return null;
        }

        $ttlHours = (int) config('openbook.federation.actor_cache_ttl_hours', 24);

        if ($existing !== null
            && $existing->last_fetched_at !== null
            && $existing->last_fetched_at->gt(Carbon::now()->subHours($ttlHours))) {
            return $existing;
        }

        return $this->fetchAndStore($actorUri) ?? $existing;
    }

    /**
     * Risolve un indirizzo federato "utente@dominio" (con o senza "acct:" o
     * "@" iniziale) tramite WebFinger sul dominio remoto, poi recupera e
     * mette in cache l'Actor indicato dal link "self" (Fase 4: ricerca
     * remota). Restituisce null se l'indirizzo e' malformato, il dominio non
     * risponde, o l'account non esiste.
     */
    public function resolveByHandle(string $handle): ?Actor
    {
        $handle = ltrim(trim($handle), '@');

        if (str_starts_with($handle, 'acct:')) {
            $handle = substr($handle, 5);
        }

        $parts = explode('@', $handle, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        [$username, $domain] = $parts;

        if ($this->domainBlocks->isBlockedHost($domain)) {
            return null;
        }

        if (strcasecmp($domain, (string) config('openbook.domain')) === 0) {
            // E' un handle locale: nessuna richiesta remota, lo risolve il
            // chiamante direttamente sulla tabella "actors" locale.
            return null;
        }

        $webfingerUrl = sprintf(
            'https://%s/.well-known/webfinger?resource=%s',
            $domain,
            urlencode('acct:'.$username.'@'.$domain)
        );

        try {
            $response = $this->httpClient->get($webfingerUrl, ['Accept' => 'application/jrd+json, application/json']);
        } catch (SsrfViolationException $exception) {
            Log::channel('single')->info('federation.webfinger_lookup_blocked', [
                'handle' => $username.'@'.$domain,
                'reason' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $document = $response->json();
        $selfUri = $this->extractSelfLink($document);

        if ($selfUri === null) {
            return null;
        }

        return $this->resolveByUri($selfUri);
    }

    /**
     * @param  array<string, mixed>|null  $document
     */
    private function extractSelfLink(?array $document): ?string
    {
        if ($document === null || ! isset($document['links']) || ! is_array($document['links'])) {
            return null;
        }

        foreach ($document['links'] as $link) {
            if (! is_array($link) || ! isset($link['href']) || ! is_string($link['href'])) {
                continue;
            }

            $type = (string) ($link['type'] ?? '');
            $rel = (string) ($link['rel'] ?? '');

            if ($rel === 'self' && (str_contains($type, 'activity+json') || str_contains($type, 'ld+json'))) {
                return $link['href'];
            }
        }

        return null;
    }

    /**
     * Forza un nuovo recupero ignorando la cache: usato quando la verifica
     * della firma fallisce e la chiave remota potrebbe essere stata ruotata
     * (un solo tentativo, per evitare cicli infiniti, come richiesto dal
     * design della Fase 3).
     */
    public function refresh(Actor $actor): ?Actor
    {
        if ($actor->is_local) {
            return null;
        }

        return $this->fetchAndStore($actor->uri);
    }

    private function fetchAndStore(string $actorUri): ?Actor
    {
        if (Actor::query()->where('uri', $actorUri)->where('is_local', true)->exists()) {
            return null;
        }

        try {
            $response = $this->httpClient->get($actorUri, [
                'Accept' => 'application/activity+json',
            ], $this->fetchSigner->resolve());
        } catch (SsrfViolationException $exception) {
            Log::channel('single')->warning('federation.actor_fetch_blocked', [
                'uri' => $actorUri,
                'reason' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        return $this->applyRemoteDocument($response->json(), $actorUri);
    }

    /**
     * Salva (creando o aggiornando) un Actor remoto a partire da un
     * documento Person/Group gia' disponibile, senza doverlo recuperare via
     * HTTP: usato sia dopo un fetch riuscito da {@see fetchAndStore()}, sia
     * da chi elabora un'attivita' "Update" in ingresso, il cui "object" e'
     * gia' il documento completo dell'Actor che l'ha inviata. "$expectedUri"
     * e' sempre l'identita' gia' verificata per altra via (l'URI richiesto
     * via HTTP, oppure l'Actor la cui firma sull'attivita' e' stata
     * validata): il documento viene scartato se dichiara un id diverso, cosi'
     * nessuno puo' spacciarsi per un Actor differente da quello per cui si e'
     * autenticato.
     *
     * @param  array<string, mixed>|null  $document
     */
    public function applyRemoteDocument(?array $document, string $expectedUri): ?Actor
    {
        if (! $this->isValidActorDocument($document, $expectedUri)) {
            return null;
        }

        if (Actor::query()->where('uri', $expectedUri)->where('is_local', true)->exists()) {
            return null;
        }

        return DB::transaction(function () use ($document): Actor {
            $uri = (string) $document['id'];
            $host = (string) (parse_url($uri, PHP_URL_HOST) ?: '');

            $attributes = [
                'type' => ($document['type'] ?? null) === 'Group' ? Actor::TYPE_GROUP : Actor::TYPE_PERSON,
                'is_local' => false,
                'preferred_username' => (string) ($document['preferredUsername'] ?? $host),
                'domain' => $host,
                'uri' => $uri,
                'name' => isset($document['name']) ? (string) $document['name'] : null,
                'summary' => isset($document['summary']) ? (string) $document['summary'] : null,
                'icon_url' => $this->extractImageUrl($document['icon'] ?? null),
                'image_url' => $this->extractImageUrl($document['image'] ?? null),
                'manually_approves_followers' => (bool) ($document['manuallyApprovesFollowers'] ?? false),
                'status' => Actor::STATUS_ACTIVE,
                'last_fetched_at' => now(),
            ];

            $actor = Actor::query()->updateOrCreate(['uri' => $uri], $attributes);

            ActorKey::query()->updateOrCreate(
                ['actor_id' => $actor->id],
                ['public_key' => (string) $document['publicKey']['publicKeyPem']]
            );

            ActorEndpoint::query()->updateOrCreate(
                ['actor_id' => $actor->id],
                [
                    'inbox' => isset($document['inbox']) ? (string) $document['inbox'] : null,
                    'outbox' => isset($document['outbox']) ? (string) $document['outbox'] : null,
                    'followers' => isset($document['followers']) ? (string) $document['followers'] : null,
                    'following' => isset($document['following']) ? (string) $document['following'] : null,
                    'shared_inbox' => isset($document['endpoints']['sharedInbox']) ? (string) $document['endpoints']['sharedInbox'] : null,
                ]
            );

            return $actor->fresh(['key', 'endpoints']);
        });
    }

    /**
     * @param  array<string, mixed>|null  $document
     */
    private function isValidActorDocument(?array $document, string $expectedUri): bool
    {
        if ($document === null || ! isset($document['id'], $document['type']) || ! is_string($document['id'])) {
            return false;
        }

        // Il documento deve auto-dichiararsi con lo stesso URI richiesto:
        // impedisce a un host di "impersonare" un Actor altrui.
        if ($document['id'] !== $expectedUri) {
            return false;
        }

        if (! in_array($document['type'], ['Person', 'Group', 'Service', 'Application', 'Organization'], true)) {
            return false;
        }

        return isset($document['publicKey']['publicKeyPem'])
            && is_string($document['publicKey']['publicKeyPem'])
            && $document['publicKey']['publicKeyPem'] !== '';
    }

    private function extractImageUrl(mixed $image): ?string
    {
        if (is_string($image)) {
            return $image;
        }

        if (is_array($image) && isset($image['url']) && is_string($image['url'])) {
            return $image['url'];
        }

        return null;
    }
}
