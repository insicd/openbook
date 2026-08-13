<?php

namespace App\Domain\Feeds;

use App\Application\Services\DomainBlockManager;
use App\Infrastructure\Security\Http\SafeHttpClient;
use App\Infrastructure\Security\Http\SsrfViolationException;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Scopre un feed RSS/Atom a partire da un URL: se la risorsa e' gia' un feed
 * lo usa; se e' HTML cerca &lt;link rel="alternate" type="application/…xml"&gt;.
 */
final class FeedDiscoverer
{
    public function __construct(
        private readonly SafeHttpClient $http,
        private readonly DomainBlockManager $domainBlocks,
        private readonly FeedDocumentParser $parser,
    ) {}

    public function discover(string $url): DiscoveredFeed
    {
        $url = $this->normalizeUrl($url);

        if ($this->domainBlocks->isBlockedUrl($url)) {
            throw new RuntimeException('Questo dominio e\' bloccato su questa istanza.');
        }

        $response = $this->fetch($url);
        $contentType = mb_strtolower((string) $response->header('Content-Type'));
        $body = $response->body;

        if ($this->looksLikeFeed($contentType, $body)) {
            return $this->fromFeedBody($url, $body, $response->header('ETag'), $response->header('Last-Modified'));
        }

        if ($this->looksLikeHtml($contentType, $body)) {
            $alternate = $this->alternateFeedUrlFromHtml($body, $url);

            if ($alternate === null) {
                throw new RuntimeException('Nessun feed RSS/Atom trovato in questa pagina.');
            }

            if ($this->domainBlocks->isBlockedUrl($alternate)) {
                throw new RuntimeException('Questo dominio e\' bloccato su questa istanza.');
            }

            $feedResponse = $this->fetch($alternate);

            return $this->fromFeedBody(
                $alternate,
                $feedResponse->body,
                $feedResponse->header('ETag'),
                $feedResponse->header('Last-Modified'),
            );
        }

        throw new RuntimeException('L\'URL non punta a un feed RSS/Atom riconoscibile.');
    }

    /**
     * @return \App\Infrastructure\Security\Http\SafeHttpResponse
     */
    public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): \App\Infrastructure\Security\Http\SafeHttpResponse
    {
        $headers = [
            'Accept' => 'application/atom+xml, application/rss+xml, application/xml, text/xml, text/html;q=0.8, */*;q=0.5',
        ];

        if ($etag !== null && $etag !== '') {
            $headers['If-None-Match'] = $etag;
        }

        if ($lastModified !== null && $lastModified !== '') {
            $headers['If-Modified-Since'] = $lastModified;
        }

        try {
            return $this->http->get($url, $headers);
        } catch (SsrfViolationException $exception) {
            throw new RuntimeException('URL non consentito.', 0, $exception);
        }
    }

    private function fromFeedBody(string $feedUrl, string $body, ?string $etag, ?string $lastModified): DiscoveredFeed
    {
        $meta = $this->parser->parseMetadata($body);

        return new DiscoveredFeed(
            feedUrl: $this->normalizeUrl($feedUrl),
            format: $meta['format'],
            title: $meta['title'],
            siteUrl: $meta['site_url'],
            summary: $meta['summary'],
            iconUrl: $meta['icon_url'],
            body: $body,
            etag: $etag,
            lastModified: $lastModified,
        );
    }

    private function looksLikeFeed(string $contentType, string $body): bool
    {
        if (str_contains($contentType, 'rss')
            || str_contains($contentType, 'atom')
            || str_contains($contentType, 'xml')) {
            return $this->parser->looksLikeFeed($body);
        }

        return $this->parser->looksLikeFeed($body);
    }

    private function looksLikeHtml(string $contentType, string $body): bool
    {
        if (str_contains($contentType, 'html')) {
            return true;
        }

        return (bool) preg_match('/<html\b/i', $body);
    }

    private function alternateFeedUrlFromHtml(string $html, string $baseUrl): ?string
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//link[@rel]');

        if ($nodes === false) {
            return null;
        }

        $candidates = [];

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $rel = mb_strtolower(trim($node->getAttribute('rel')));
            $type = mb_strtolower(trim($node->getAttribute('type')));
            $href = trim($node->getAttribute('href'));

            if ($href === '' || ! str_contains($rel, 'alternate')) {
                continue;
            }

            $isAtom = str_contains($type, 'atom');
            $isRss = str_contains($type, 'rss') || $type === 'application/xml' || $type === 'text/xml';

            if (! $isAtom && ! $isRss) {
                continue;
            }

            $absolute = $this->absolutize($href, $baseUrl);

            if ($absolute === null) {
                continue;
            }

            $candidates[] = [
                'url' => $absolute,
                'priority' => $isAtom ? 0 : 1,
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return $candidates[0]['url'];
    }

    private function absolutize(string $href, string $baseUrl): ?string
    {
        if (preg_match('#^https?://#i', $href) === 1) {
            return $this->normalizeUrl($href);
        }

        $parts = parse_url($baseUrl);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'].'://'.$parts['host'];

        if (! empty($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        if (str_starts_with($href, '//')) {
            return $this->normalizeUrl($parts['scheme'].':'.$href);
        }

        if (str_starts_with($href, '/')) {
            return $this->normalizeUrl($origin.$href);
        }

        $basePath = $parts['path'] ?? '/';
        $directory = str_contains($basePath, '/') ? substr($basePath, 0, (int) strrpos($basePath, '/') + 1) : '/';

        return $this->normalizeUrl($origin.$directory.$href);
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            throw new RuntimeException('URL del feed non valido.');
        }

        if (preg_match('#^https?://#i', $url) !== 1) {
            throw new RuntimeException('L\'URL del feed deve iniziare con http:// o https://.');
        }

        $parts = parse_url($url);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException('URL del feed non valido.');
        }

        $normalized = strtolower($parts['scheme']).'://'.strtolower($parts['host']);

        if (! empty($parts['port'])) {
            $normalized .= ':'.$parts['port'];
        }

        $path = $parts['path'] ?? '/';
        $normalized .= $path === '' ? '/' : $path;

        if (! empty($parts['query'])) {
            $normalized .= '?'.$parts['query'];
        }

        return $normalized;
    }
}
