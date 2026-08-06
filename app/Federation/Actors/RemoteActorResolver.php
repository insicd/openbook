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
     * Risolve l'Actor firmatario a partire dal keyId della Signature HTTP.
     *
     * Formati supportati:
     * - Mastodon / tipico: {@code https://host/users/alice#main-key}
     *   (frammento → URI Actor)
     * - tags.pub / activitypub-bot: {@code https://host/user/alice/publickey}
     *   (documento {@code CryptographicKey} con {@code owner} + {@code publicKeyPem})
     *
     * @param  string  $keyId
     */
    public function resolveByKeyId(string $keyId): ?Actor
    {
        if ($this->domainBlocks->isBlockedUrl($keyId)) {
            return null;
        }

        $withoutFragment = explode('#', $keyId, 2)[0];

        // keyId con frammento: e' l'URI Actor (caso Mastodon).
        if ($withoutFragment !== $keyId) {
            return $this->resolveByUri($withoutFragment);
        }

        // Dopo un Follow abbiamo gia' Actor + PEM dal documento Person.
        // Preferisci la cache: un GET firmato verso …/publickey su tags.pub
        // puo' tornare 400 (Signature non verificabile su risorse pubbliche)
        // e far fallire altrimenti l'Accept del follow-back.
        $cachedOwner = $this->resolveCachedOwnerForKeyId($keyId);

        if ($cachedOwner !== null) {
            return $cachedOwner;
        }

        // keyId senza frammento: CryptographicKey (tags.pub) o, raro, URI Actor.
        // true = rifiuto definitivo (es. owner su altro host): niente fallback.
        $fromKeyDocument = $this->resolveFromKeyDocumentUrl($keyId);

        if ($fromKeyDocument === false) {
            return null;
        }

        if ($fromKeyDocument !== null) {
            return $fromKeyDocument;
        }

        $ownerUri = $this->guessOwnerUriFromKeyId($keyId);

        if ($ownerUri !== null) {
            return $this->resolveByUri($ownerUri);
        }

        return $this->resolveByUri($keyId);
    }

    /**
     * Se il keyId segue il pattern activitypub-bot {@code …/publickey} e
     * l'owner e' gia' in cache con una PEM, riusala senza rete.
     */
    private function resolveCachedOwnerForKeyId(string $keyId): ?Actor
    {
        $ownerUri = $this->guessOwnerUriFromKeyId($keyId);

        if ($ownerUri === null) {
            return null;
        }

        $actor = Actor::query()
            ->where('uri', $ownerUri)
            ->where('is_local', false)
            ->with(['key', 'endpoints'])
            ->first();

        if ($actor === null || $actor->key === null || blank($actor->key->public_key)) {
            return null;
        }

        return $actor;
    }

    /**
     * Deriva l'URI Actor da un keyId standalone noto (tags.pub / activitypub-bot).
     */
    private function guessOwnerUriFromKeyId(string $keyId): ?string
    {
        if (preg_match('#^(https?://.+)/publickey$#i', $keyId, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Recupera un documento all'URL del keyId: se e' un CryptographicKey,
     * risolve l'Actor "owner" e aggiorna la chiave pubblica in cache.
     *
     * @return Actor|false|null Actor se risolto; false se il documento e'
     *                          un CryptographicKey rifiutato (stop); null se
     *                          il fetch non ha prodotto una chiave usabile
     *                          (il chiamante puo' tentare altri fallback).
     */
    private function resolveFromKeyDocumentUrl(string $keyId): Actor|false|null
    {
        try {
            // Le chiavi pubbliche sono risorse pubbliche: niente authorized
            // fetch. tags.pub / activitypub-bot rispondono 400 ai GET firmati
            // non verificabili invece di servire il documento.
            $response = $this->httpClient->get($keyId, [
                'Accept' => 'application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
            ]);
        } catch (SsrfViolationException $exception) {
            Log::channel('single')->info('federation.key_fetch_blocked', [
                'key_id' => $keyId,
                'reason' => $exception->getMessage(),
            ]);

            return null;
        } catch (\Throwable $exception) {
            Log::channel('single')->info('federation.key_fetch_failed', [
                'key_id' => $keyId,
                'reason' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $document = $response->json();

        if (! is_array($document)) {
            return null;
        }

        if ($this->isCryptographicKeyDocument($document, $keyId)) {
            return $this->resolveActorFromCryptographicKey($document, $keyId) ?? false;
        }

        // Documento CryptographicKey presente ma non accettabile (es. owner
        // su altro host): non tentare euristiche sull'owner dal path.
        if ($this->looksLikeRejectedCryptographicKey($document, $keyId)) {
            return false;
        }

        // Alcuni server rispondono all'URL della chiave con l'Actor che la
        // incorpora (publicKey.id == keyId).
        if ($this->isValidActorDocument($document, (string) ($document['id'] ?? ''))
            && is_string($document['publicKey']['id'] ?? null)
            && $document['publicKey']['id'] === $keyId
        ) {
            return $this->applyRemoteDocument($document, (string) $document['id']);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function looksLikeRejectedCryptographicKey(array $document, string $keyId): bool
    {
        $id = $document['id'] ?? null;
        $owner = $document['owner'] ?? null;
        $pem = $document['publicKeyPem'] ?? null;
        $type = $document['type'] ?? null;

        if ($type !== null && $type !== 'CryptographicKey') {
            return false;
        }

        if (! is_string($id) || $id !== $keyId) {
            return false;
        }

        return is_string($owner) && $owner !== '' && is_string($pem) && $pem !== '';
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function isCryptographicKeyDocument(array $document, string $keyId): bool
    {
        $id = $document['id'] ?? null;
        $owner = $document['owner'] ?? null;
        $pem = $document['publicKeyPem'] ?? null;
        $type = $document['type'] ?? null;

        if (! is_string($id) || $id !== $keyId) {
            return false;
        }

        if (! is_string($owner) || $owner === '' || ! is_string($pem) || $pem === '') {
            return false;
        }

        // type assente o CryptographicKey (activitypub-bot / W3C security vocabulary).
        if ($type !== null && $type !== 'CryptographicKey') {
            return false;
        }

        $keyHost = parse_url($keyId, PHP_URL_HOST);
        $ownerHost = parse_url($owner, PHP_URL_HOST);

        if (! is_string($keyHost) || ! is_string($ownerHost) || strcasecmp($keyHost, $ownerHost) !== 0) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function resolveActorFromCryptographicKey(array $document, string $keyId): ?Actor
    {
        $owner = (string) $document['owner'];
        $pem = (string) $document['publicKeyPem'];

        $actor = $this->resolveByUri($owner);

        if ($actor === null) {
            return null;
        }

        ActorKey::query()->updateOrCreate(
            ['actor_id' => $actor->id],
            ['public_key' => $pem]
        );

        Log::channel('single')->debug('federation.key_resolved_from_cryptographic_key', [
            'key_id' => $keyId,
            'owner' => $owner,
            'actor_id' => $actor->id,
        ]);

        return $actor->fresh(['key', 'endpoints']);
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

        // Mai trattare il dominio locale come remoto: evita UniqueConstraint su
        // (preferred_username, domain) quando in replies ricompare un commento
        // nostro e l'URI in cache e' ancora un alias legacy (/@user).
        if ($this->isLocalDomainUri($actorUri)) {
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

    private function isLocalDomainUri(string $uri): bool
    {
        $host = parse_url($uri, PHP_URL_HOST);
        $domain = (string) config('openbook.domain');

        if (! is_string($host) || $domain === '') {
            return false;
        }

        $domainHost = parse_url('//'.$domain, PHP_URL_HOST);

        if (! is_string($domainHost) || $domainHost === '') {
            $domainHost = $domain;
        }

        return strcasecmp($host, $domainHost) === 0;
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
