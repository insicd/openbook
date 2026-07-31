<?php

namespace App\Federation\Inbox;

use App\Federation\Actors\Actor;
use App\Federation\Fetch\FederationFetchSigner;
use App\Infrastructure\Security\Http\SafeHttpClient;
use App\Infrastructure\Security\Http\SsrfViolationException;

/**
 * Recupera un documento Note/Page remoto via HTTP (Accept: activity+json),
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

        return RemotePostObject::unwrap($document);
    }
}
