<?php

namespace App\Federation\Inbox;

use App\Application\Services\DomainBlockManager;
use App\Federation\Actors\Actor;
use App\Federation\Fetch\FederationFetchSigner;
use App\Infrastructure\Security\Http\SafeHttpClient;
use App\Infrastructure\Security\Http\SsrfViolationException;

/**
 * Recupera un documento postabile remoto (Note/Page/Article/Video/Image)
 * via HTTP (Accept: activity+json), usato quando un Group ritrasmette un
 * post non ancora in cache o quando un Create porta solo l'id dell'oggetto.
 */
final class RemoteNoteDocumentFetcher
{
    public function __construct(
        private readonly SafeHttpClient $httpClient,
        private readonly FederationFetchSigner $fetchSigner,
        private readonly DomainBlockManager $domainBlocks,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function fetch(string $uri, ?Actor $signingActor = null): ?array
    {
        $document = $this->fetchDocument($uri, $signingActor);

        return $document !== null ? RemotePostObject::unwrap($document) : null;
    }

    /**
     * Documento ActivityStreams grezzo, usato quando il tipo dell'oggetto
     * riferito per URI (post oppure evento) non e' ancora noto.
     *
     * @return array<string, mixed>|null
     */
    public function fetchDocument(string $uri, ?Actor $signingActor = null): ?array
    {
        if ($this->domainBlocks->isBlockedUrl($uri)) {
            return null;
        }

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

        return $document;
    }
}
