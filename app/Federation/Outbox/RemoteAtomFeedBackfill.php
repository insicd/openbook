<?php

namespace App\Federation\Outbox;

use App\Federation\Actors\Actor;
use App\Federation\Inbox\RemoteNoteDocumentFetcher;
use App\Federation\Inbox\RemotePostObject;
use App\Infrastructure\Security\Http\SafeHttpClient;
use App\Infrastructure\Security\Http\SsrfViolationException;
use DOMDocument;
use DOMXPath;

/**
 * Recupero post recenti quando l'outbox ActivityPub e' uno stub (tipico di
 * Pixelfed: OrderedCollection con solo totalItems, senza first/orderedItems).
 * Usa il feed Atom `https://…/users/{name}.atom` (anche in WebFinger come
 * updates-from): ogni entry punta a una Note che viene poi fetchata in AP.
 */
final class RemoteAtomFeedBackfill
{
    public function __construct(
        private readonly SafeHttpClient $httpClient,
        private readonly RemoteNoteDocumentFetcher $noteDocumentFetcher,
    ) {}

    /**
     * @return list<array<string, mixed>> Note/Page/… gia' materializzate
     */
    public function fetchNotes(Actor $actor, ?Actor $signingActor, int $limit = 20): array
    {
        $atomUrl = $this->atomUrlFor($actor);

        if ($atomUrl === null) {
            return [];
        }

        try {
            $response = $this->httpClient->get(
                $atomUrl,
                ['Accept' => 'application/atom+xml, application/xml, text/xml, application/rss+xml'],
                $signingActor,
            );
        } catch (SsrfViolationException) {
            return [];
        } catch (\Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $uris = $this->statusUrisFromAtom($response->body);
        $notes = [];

        foreach (array_slice($uris, 0, $limit) as $uri) {
            $note = $this->noteDocumentFetcher->fetch($uri, $signingActor);

            if ($note === null || ! RemotePostObject::isPostable($note['type'] ?? null)) {
                continue;
            }

            $notes[] = $note;
        }

        return $notes;
    }

    private function atomUrlFor(Actor $actor): ?string
    {
        $uri = rtrim($actor->uri, '/');

        if ($uri === '' || (! str_starts_with($uri, 'https://') && ! str_starts_with($uri, 'http://'))) {
            return null;
        }

        // Pixelfed / Mastodon: https://host/users/name.atom
        return $uri.'.atom';
    }

    /**
     * @return list<string>
     */
    private function statusUrisFromAtom(string $xml): array
    {
        if ($xml === '' || ! str_contains($xml, '<')) {
            return [];
        }

        $document = new DOMDocument;

        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            return [];
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('atom', 'http://www.w3.org/2005/Atom');

        $uris = [];

        foreach ($xpath->query('//atom:entry') ?: [] as $entry) {
            $id = $xpath->evaluate('string(atom:id)', $entry);
            $alternate = $xpath->evaluate('string(atom:link[@rel="alternate"][@href][1]/@href)', $entry);

            foreach ([$id, $alternate] as $candidate) {
                if (! is_string($candidate) || $candidate === '') {
                    continue;
                }

                if (filter_var($candidate, FILTER_VALIDATE_URL) === false) {
                    continue;
                }

                $scheme = strtolower((string) parse_url($candidate, PHP_URL_SCHEME));

                if (! in_array($scheme, ['http', 'https'], true)) {
                    continue;
                }

                $uris[] = $candidate;

                break;
            }
        }

        return array_values(array_unique($uris));
    }
}
