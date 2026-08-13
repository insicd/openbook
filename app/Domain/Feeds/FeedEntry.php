<?php

namespace App\Domain\Feeds;

/**
 * Voce di un feed RSS/Atom pronta per diventare un Post locale.
 */
final readonly class FeedEntry
{
    public function __construct(
        public string $uri,
        public string $title,
        public string $body,
        public ?string $link,
        public ?\DateTimeInterface $publishedAt,
    ) {}
}
