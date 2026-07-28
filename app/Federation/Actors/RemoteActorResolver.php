<?php

namespace App\Federation\Actors;

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
    ) {}

    /**
     * @param  string  $keyId  es. "https://remoto.example/users/alice#main-key"
     */
    public function resolveByKeyId(string $keyId): ?Actor
    {
        return $this->resolveByUri(explode('#', $keyId, 2)[0]);
    }

    /**
     * Risolve un Actor a partire dal suo URI ActivityPub, usando la cache
     * locale finche' non e' scaduta ({@see config('openbook.federation.actor_cache_ttl_hours')}).
     */
    public function resolveByUri(string $actorUri): ?Actor
    {
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
            ]);
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

        $document = $response->json();

        if (! $this->isValidActorDocument($document, $actorUri)) {
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
