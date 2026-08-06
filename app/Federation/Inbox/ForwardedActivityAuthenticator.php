<?php

namespace App\Federation\Inbox;

use App\Application\Services\DomainBlockManager;
use App\Federation\Fetch\FederationFetchSigner;
use App\Infrastructure\Security\Http\SafeHttpClient;
use App\Infrastructure\Security\Http\SsrfViolationException;
use App\Infrastructure\Security\LinkedData\LinkedDataSignature;
use Illuminate\Support\Facades\Log;

/**
 * Autentica un'attivita' inoltrata (inbox forwarding) quando la firma HTTP
 * appartiene a un Actor diverso da {@code activity.actor}.
 *
 * Ordine (allineato a Mastodon + Primer ActivityPub):
 * 1. Linked Data Signature {@code RsaSignature2017} sul payload
 * 2. altrimenti refetch same-origin dell'id attivita'
 */
final class ForwardedActivityAuthenticator
{
    public function __construct(
        private readonly SafeHttpClient $httpClient,
        private readonly FederationFetchSigner $fetchSigner,
        private readonly DomainBlockManager $domainBlocks,
        private readonly LinkedDataSignature $linkedDataSignatures,
    ) {}

    /**
     * @param  array<string, mixed>  $activity  Payload consegnato dal forwarder
     * @return array<string, mixed>|null        Documento autenticato
     */
    public function authenticate(array $activity, string $claimedActorUri): ?array
    {
        if ($this->linkedDataSignatures->verifyActor($activity, $claimedActorUri) !== null) {
            Log::channel('single')->info('federation.inbox.forward_ld_ok', [
                'activity_id' => $activity['id'] ?? null,
                'claimed_actor' => $claimedActorUri,
            ]);

            return $activity;
        }

        return $this->authenticateFromOrigin($activity, $claimedActorUri);
    }

    /**
     * @param  array<string, mixed>  $activity  Payload consegnato dal forwarder
     * @return array<string, mixed>|null        Documento autenticato dall'origine
     */
    public function authenticateFromOrigin(array $activity, string $claimedActorUri): ?array
    {
        $activityId = is_string($activity['id'] ?? null) ? $activity['id'] : null;

        if ($activityId === null || $activityId === '') {
            return null;
        }

        if ($this->domainBlocks->isBlockedUrl($claimedActorUri) || $this->domainBlocks->isBlockedUrl($activityId)) {
            return null;
        }

        if ($this->isLocalHostUri($activityId) || $this->isLocalHostUri($claimedActorUri)) {
            return null;
        }

        if (! $this->sameOrigin($activityId, $claimedActorUri)) {
            Log::channel('single')->info('federation.inbox.forward_rejected', [
                'reason' => 'activity_not_same_origin_as_actor',
                'activity_id' => $activityId,
                'claimed_actor' => $claimedActorUri,
            ]);

            return null;
        }

        $document = $this->fetchActivityDocument($activityId);

        if ($document === null) {
            Log::channel('single')->info('federation.inbox.forward_rejected', [
                'reason' => 'origin_fetch_failed',
                'activity_id' => $activityId,
                'claimed_actor' => $claimedActorUri,
            ]);

            return null;
        }

        $fetchedId = is_string($document['id'] ?? null) ? $document['id'] : null;
        $fetchedActor = $this->actorUri($document['actor'] ?? null);
        $fetchedType = is_string($document['type'] ?? null) ? $document['type'] : null;

        if ($fetchedId === null || $fetchedActor === null || $fetchedType === null || $fetchedType === '') {
            return null;
        }

        if ($this->normalizeUri($fetchedId) !== $this->normalizeUri($activityId)) {
            Log::channel('single')->info('federation.inbox.forward_rejected', [
                'reason' => 'origin_id_mismatch',
                'activity_id' => $activityId,
                'fetched_id' => $fetchedId,
            ]);

            return null;
        }

        if ($this->normalizeUri($fetchedActor) !== $this->normalizeUri($claimedActorUri)) {
            Log::channel('single')->info('federation.inbox.forward_rejected', [
                'reason' => 'origin_actor_mismatch',
                'claimed_actor' => $claimedActorUri,
                'fetched_actor' => $fetchedActor,
            ]);

            return null;
        }

        return $document;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchActivityDocument(string $uri): ?array
    {
        try {
            $response = $this->httpClient->get($uri, [
                'Accept' => 'application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
            ], $this->fetchSigner->resolve());
        } catch (SsrfViolationException $exception) {
            Log::channel('single')->info('federation.inbox.forward_fetch_blocked', [
                'uri' => $uri,
                'reason' => $exception->getMessage(),
            ]);

            return null;
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $document = $response->json();

        return is_array($document) ? $document : null;
    }

    private function actorUri(mixed $actor): ?string
    {
        if (is_string($actor) && $actor !== '') {
            return $actor;
        }

        if (is_array($actor) && is_string($actor['id'] ?? null) && $actor['id'] !== '') {
            return $actor['id'];
        }

        return null;
    }

    private function sameOrigin(string $left, string $right): bool
    {
        $a = parse_url($left);
        $b = parse_url($right);

        if (! is_array($a) || ! is_array($b)
            || ! isset($a['scheme'], $a['host'], $b['scheme'], $b['host'])
        ) {
            return false;
        }

        if (strcasecmp((string) $a['scheme'], (string) $b['scheme']) !== 0) {
            return false;
        }

        if (strcasecmp((string) $a['host'], (string) $b['host']) !== 0) {
            return false;
        }

        $portA = $a['port'] ?? ($a['scheme'] === 'https' ? 443 : 80);
        $portB = $b['port'] ?? ($b['scheme'] === 'https' ? 443 : 80);

        return (int) $portA === (int) $portB;
    }

    private function isLocalHostUri(string $uri): bool
    {
        $host = parse_url($uri, PHP_URL_HOST);
        $domain = (string) config('openbook.domain');

        if (! is_string($host) || $host === '' || $domain === '') {
            return false;
        }

        $domainHost = parse_url('//'.$domain, PHP_URL_HOST);

        if (! is_string($domainHost) || $domainHost === '') {
            $domainHost = $domain;
        }

        return strcasecmp($host, $domainHost) === 0;
    }

    private function normalizeUri(string $uri): string
    {
        return rtrim($uri, '/');
    }
}
