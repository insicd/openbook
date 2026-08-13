<?php

namespace App\Domain\Feeds;

use App\Federation\Inbox\RemoteContentSanitizer;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Analizza documenti Atom e RSS 2.0: metadati del canale e elenco voci.
 */
final class FeedDocumentParser
{
    public function looksLikeFeed(string $body): bool
    {
        $trimmed = ltrim($body);

        if ($trimmed === '') {
            return false;
        }

        if (str_starts_with($trimmed, '{')) {
            return false;
        }

        return (bool) preg_match('/<(rss|feed|rdf:RDF)\b/i', $trimmed);
    }

    /**
     * @return array{
     *     format: string,
     *     title: string,
     *     site_url: ?string,
     *     summary: ?string,
     *     icon_url: ?string
     * }
     */
    public function parseMetadata(string $body): array
    {
        $xpath = $this->xpath($body);
        $root = $xpath->document->documentElement;

        if ($root === null) {
            throw new RuntimeException('Documento feed non valido.');
        }

        $rootName = strtolower($root->localName ?: $root->nodeName);

        if ($rootName === 'feed') {
            return [
                'format' => FeedSource::FORMAT_ATOM,
                'title' => $this->firstText($xpath, '/*[local-name()="feed"]/*[local-name()="title"]') ?: 'Feed',
                'site_url' => $this->atomAlternateLink($xpath),
                'summary' => $this->nullableText($xpath, '/*[local-name()="feed"]/*[local-name()="subtitle"]'),
                'icon_url' => $this->nullableText($xpath, '/*[local-name()="feed"]/*[local-name()="icon"]')
                    ?: $this->nullableText($xpath, '/*[local-name()="feed"]/*[local-name()="logo"]'),
            ];
        }

        if ($rootName === 'rss' || $rootName === 'rdf') {
            return [
                'format' => FeedSource::FORMAT_RSS,
                'title' => $this->firstText($xpath, '/*[local-name()="rss"]/*[local-name()="channel"]/*[local-name()="title"]')
                    ?: $this->firstText($xpath, '/*[local-name()="RDF"]/*[local-name()="channel"]/*[local-name()="title"]')
                    ?: 'Feed',
                'site_url' => $this->nullableText($xpath, '/*[local-name()="rss"]/*[local-name()="channel"]/*[local-name()="link"]')
                    ?: $this->nullableText($xpath, '/*[local-name()="RDF"]/*[local-name()="channel"]/*[local-name()="link"]'),
                'summary' => $this->nullableText($xpath, '/*[local-name()="rss"]/*[local-name()="channel"]/*[local-name()="description"]')
                    ?: $this->nullableText($xpath, '/*[local-name()="RDF"]/*[local-name()="channel"]/*[local-name()="description"]'),
                'icon_url' => $this->nullableText($xpath, '/*[local-name()="rss"]/*[local-name()="channel"]/*[local-name()="image"]/*[local-name()="url"]'),
            ];
        }

        throw new RuntimeException('Formato feed non supportato.');
    }

    /**
     * @return list<FeedEntry>
     */
    public function parseEntries(string $body, int $limit = 40): array
    {
        $xpath = $this->xpath($body);
        $root = $xpath->document->documentElement;

        if ($root === null) {
            return [];
        }

        $rootName = strtolower($root->localName ?: $root->nodeName);
        $entries = [];

        if ($rootName === 'feed') {
            foreach ($xpath->query('/*[local-name()="feed"]/*[local-name()="entry"]') ?: [] as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                $entry = $this->atomEntry($xpath, $node);

                if ($entry !== null) {
                    $entries[] = $entry;
                }

                if (count($entries) >= $limit) {
                    break;
                }
            }

            return $entries;
        }

        $itemQuery = $rootName === 'rdf'
            ? '/*[local-name()="RDF"]/*[local-name()="item"]'
            : '/*[local-name()="rss"]/*[local-name()="channel"]/*[local-name()="item"]';

        foreach ($xpath->query($itemQuery) ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $entry = $this->rssItem($xpath, $node);

            if ($entry !== null) {
                $entries[] = $entry;
            }

            if (count($entries) >= $limit) {
                break;
            }
        }

        return $entries;
    }

    private function atomEntry(DOMXPath $xpath, DOMElement $node): ?FeedEntry
    {
        $id = $this->childText($xpath, $node, 'id');
        $link = $this->atomEntryLink($xpath, $node);
        $uri = $id !== null && $id !== '' ? $id : $link;

        if ($uri === null || $uri === '') {
            return null;
        }

        $title = $this->childText($xpath, $node, 'title') ?: 'Senza titolo';
        $content = $this->childHtml($xpath, $node, 'content')
            ?: $this->childHtml($xpath, $node, 'summary')
            ?: $title;
        $published = $this->parseDate(
            $this->childText($xpath, $node, 'published')
                ?: $this->childText($xpath, $node, 'updated')
        );

        $body = RemoteContentSanitizer::toPlainText($content);

        if ($link !== null && $link !== '' && ! str_contains($body, $link)) {
            $body = trim($body."\n\n".$link);
        }

        if ($body === '') {
            $body = $title.($link ? "\n\n".$link : '');
        }

        return new FeedEntry($uri, $title, $body, $link, $published);
    }

    private function rssItem(DOMXPath $xpath, DOMElement $node): ?FeedEntry
    {
        $guid = $this->childText($xpath, $node, 'guid');
        $link = $this->childText($xpath, $node, 'link');
        $uri = $guid !== null && $guid !== '' ? $guid : $link;

        if ($uri === null || $uri === '') {
            return null;
        }

        $title = $this->childText($xpath, $node, 'title') ?: 'Senza titolo';
        $content = $this->childHtml($xpath, $node, 'encoded')
            ?: $this->childHtml($xpath, $node, 'description')
            ?: $title;
        $published = $this->parseDate($this->childText($xpath, $node, 'pubDate'));

        $body = RemoteContentSanitizer::toPlainText($content);

        if ($link !== null && $link !== '' && ! str_contains($body, $link)) {
            $body = trim($body."\n\n".$link);
        }

        if ($body === '') {
            $body = $title.($link ? "\n\n".$link : '');
        }

        return new FeedEntry($uri, $title, $body, $link, $published);
    }

    private function xpath(string $body): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new RuntimeException('Impossibile analizzare il documento del feed.');
        }

        return new DOMXPath($document);
    }

    private function atomAlternateLink(DOMXPath $xpath): ?string
    {
        $nodes = $xpath->query('/*[local-name()="feed"]/*[local-name()="link"]');

        if ($nodes === false) {
            return null;
        }

        $fallback = null;

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $href = trim($node->getAttribute('href'));

            if ($href === '') {
                continue;
            }

            $rel = mb_strtolower(trim($node->getAttribute('rel') ?: 'alternate'));

            if ($rel === 'alternate') {
                return $href;
            }

            $fallback ??= $href;
        }

        return $fallback;
    }

    private function atomEntryLink(DOMXPath $parentXpath, DOMElement $entry): ?string
    {
        $xpath = new DOMXPath($entry->ownerDocument);
        $nodes = $xpath->query('./*[local-name()="link"]', $entry);

        if ($nodes === false) {
            return null;
        }

        $fallback = null;

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $href = trim($node->getAttribute('href'));

            if ($href === '') {
                continue;
            }

            $rel = mb_strtolower(trim($node->getAttribute('rel') ?: 'alternate'));

            if ($rel === 'alternate') {
                return $href;
            }

            $fallback ??= $href;
        }

        return $fallback;
    }

    private function firstText(DOMXPath $xpath, string $query): string
    {
        return (string) ($this->nullableText($xpath, $query) ?? '');
    }

    private function nullableText(DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);
        $node = $nodes !== false ? $nodes->item(0) : null;

        if ($node === null) {
            return null;
        }

        $text = trim(html_entity_decode($node->textContent ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text !== '' ? $text : null;
    }

    private function childText(DOMXPath $xpath, DOMElement $parent, string $localName): ?string
    {
        $nodes = $xpath->query('./*[local-name()="'.$localName.'"]', $parent);
        $node = $nodes !== false ? $nodes->item(0) : null;

        if ($node === null) {
            return null;
        }

        $text = trim(html_entity_decode($node->textContent ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text !== '' ? $text : null;
    }

    private function childHtml(DOMXPath $xpath, DOMElement $parent, string $localName): ?string
    {
        $nodes = $xpath->query('./*[local-name()="'.$localName.'"]', $parent);
        $node = $nodes !== false ? $nodes->item(0) : null;

        if (! $node instanceof DOMElement) {
            return null;
        }

        $html = '';

        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument->saveHTML($child);
        }

        $html = trim($html);

        if ($html === '') {
            $html = trim($node->textContent ?? '');
        }

        return $html !== '' ? $html : null;
    }

    private function parseDate(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
