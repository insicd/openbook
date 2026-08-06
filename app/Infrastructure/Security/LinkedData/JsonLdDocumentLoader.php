<?php

namespace App\Infrastructure\Security\LinkedData;

use JsonLdException;
use RuntimeException;
use stdClass;

/**
 * Document loader JSON-LD che serve solo contesti locali (niente fetch HTTP
 * di {@code @context}): evita SSRF e dipendenze di rete in verifica firma.
 */
final class JsonLdDocumentLoader
{
    /**
     * @var array<string, string>
     */
    private const CONTEXT_FILES = [
        'https://w3id.org/identity/v1' => 'identity-v1.jsonld',
        'https://w3id.org/security/v1' => 'security-v1.jsonld',
        'https://www.w3.org/ns/activitystreams' => 'activitystreams.jsonld',
        'https://www.w3.org/ns/activitystreams#' => 'activitystreams.jsonld',
        'http://joinmastodon.org/ns' => 'joinmastodon.jsonld',
        'http://joinmastodon.org/ns#' => 'joinmastodon.jsonld',
    ];

    private bool $registered = false;

    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        jsonld_set_document_loader($this->load(...));
        $this->registered = true;
    }

    /**
     * @throws JsonLdException
     */
    public function load(string $url): stdClass
    {
        $path = $this->resolvePath($url);

        if ($path === null) {
            throw new JsonLdException(
                'Contesto JSON-LD non in allowlist locale: '.$url,
                'jsonld.LoadDocumentError',
                'loading document failed',
            );
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new JsonLdException(
                'Impossibile leggere il contesto JSON-LD locale: '.$url,
                'jsonld.LoadDocumentError',
                'loading document failed',
            );
        }

        $document = json_decode($raw);

        if (! is_object($document) && ! is_array($document)) {
            throw new JsonLdException(
                'Contesto JSON-LD locale non valido: '.$url,
                'jsonld.LoadDocumentError',
                'loading document failed',
            );
        }

        return (object) [
            'contextUrl' => null,
            'documentUrl' => $url,
            'document' => $document,
        ];
    }

    private function resolvePath(string $url): ?string
    {
        $file = self::CONTEXT_FILES[$url] ?? null;

        if ($file === null) {
            // Alcuni documenti usano lo slash finale o varianti http/https.
            $normalized = rtrim($url, '#');
            $file = self::CONTEXT_FILES[$normalized]
                ?? self::CONTEXT_FILES[$normalized.'/']
                ?? self::CONTEXT_FILES[preg_replace('#^http:#', 'https:', $url) ?? $url]
                ?? null;
        }

        if ($file === null) {
            return null;
        }

        $path = resource_path('jsonld/'.$file);

        return is_file($path) ? $path : null;
    }
}
