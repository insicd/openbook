<?php

namespace App\Domain\Posts;

/**
 * Descrizione di un video da incorporare sotto il body di un post:
 * solo l'URL "embed" sicuro da usare in un iframe (mai l'URL originale
 * di watch, che non e' pensato per essere caricato in un frame).
 */
final class VideoEmbed
{
    public const PROVIDER_YOUTUBE = 'youtube';

    public const PROVIDER_PEERTUBE = 'peertube';

    public function __construct(
        public readonly string $provider,
        public readonly string $embedUrl,
        public readonly string $sourceUrl,
    ) {}
}
