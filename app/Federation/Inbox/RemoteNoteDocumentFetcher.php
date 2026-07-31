<?php

namespace App\Federation\Inbox;

use App\Federation\Actors\Actor;
use App\Federation\Fetch\FederationFetchSigner;
use App\Infrastructure\Security\Http\SafeHttpClient;
use App\Infrastructure\Security\Http\SsrfViolationException;

/**
 * Recupera un documento Note remoto via HTTP (Accept: activity+json),
 * usato quando un Group ritrasmette un post non ancora in cache locale.
 */
final class RemoteNoteDocumentFetcher
{
    public function __construct(
        private readonly SafeHttpClient $httpClient,
        private readonly FederationFetchSigner $fetchSigner,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function fetch(string $uri, ?Actor $signingActor = null): ?array
    {
        $signingActor ??= $this->fetchSigner->resolve();

        try {
            $response = $this->httpClient->get($uri, ['Accept' => 'application/activity+json'], $signingActor);
        } catch (SsrfViolationException) {
            return null;
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $document = $response->json();

        if (! is_array($document)) {
            return null;
        }

        if (($document['type'] ?? null) === 'Note') {
            return $document;
        }

        // Alcuni server avvolgono la Note in un Create.
        if (($document['type'] ?? null) === 'Create' && is_array($document['object'] ?? null)) {
            $inner = $document['object'];

            return ($inner['type'] ?? null) === 'Note' ? $inner : null;
        }

        return null;
    }
}
