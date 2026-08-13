<?php

namespace App\Domain\Feeds;

/**
 * Feed RSS/Atom scoperto da un URL (feed diretto o pagina HTML con
 * &lt;link rel="alternate"&gt;).
 */
final readonly class DiscoveredFeed
{
    public function __construct(
        public string $feedUrl,
        public string $format,
        public string $title,
        public ?string $siteUrl,
        public ?string $summary,
        public ?string $iconUrl,
        public string $body,
        public ?string $etag = null,
        public ?string $lastModified = null,
    ) {}
}
