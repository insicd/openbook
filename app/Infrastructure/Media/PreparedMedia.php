<?php

namespace App\Infrastructure\Media;

/** Descrive un file privato gia' pronto per essere importato nel media store. */
final readonly class PreparedMedia
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $originalName,
        public string $mimeType,
        public int $byteSize,
        public ?int $width,
        public ?int $height,
        public ?string $altText,
        public ?string $thumbnailPath = null,
    ) {}
}
