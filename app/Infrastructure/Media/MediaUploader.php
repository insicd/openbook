<?php

namespace App\Infrastructure\Media;

use App\Federation\Actors\Actor;
use App\Infrastructure\Media\Concerns\ManipulatesImagesWithGd;
use App\Infrastructure\Media\Concerns\NormalizesPublicDiskPermissions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Gestisce il caricamento di un'immagine allegata a un post: valida il tipo
 * effettivo del file (mai la sola estensione), genera un nome casuale,
 * rimuove i metadati EXIF sensibili e produce una miniatura in modo
 * sincrono con GD, compatibile con shared hosting (nessuna coda richiesta
 * per un singolo ridimensionamento).
 */
final class MediaUploader
{
    use ManipulatesImagesWithGd, NormalizesPublicDiskPermissions;

    private const ALLOWED_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    private const THUMBNAIL_MAX_DIMENSION = 640;

    public function store(UploadedFile $file, Actor $actor, ?string $altText = null): Media
    {
        $mimeType = (string) $file->getMimeType();
        $allowedMimeTypes = (array) config('openbook.media.allowed_mime_types');

        if (! in_array($mimeType, $allowedMimeTypes, true) || ! isset(self::ALLOWED_EXTENSIONS[$mimeType])) {
            throw new InvalidArgumentException("Tipo di file non consentito: {$mimeType}.");
        }

        $maxBytes = (int) config('openbook.media.max_size_kb') * 1024;

        if ($file->getSize() === false || $file->getSize() > $maxBytes) {
            throw new InvalidArgumentException('Il file supera la dimensione massima consentita.');
        }

        $dimensions = @getimagesize($file->getRealPath());

        if ($dimensions === false) {
            throw new InvalidArgumentException('Il file non e un\'immagine valida.');
        }

        [$width, $height] = $dimensions;

        $extension = self::ALLOWED_EXTENSIONS[$mimeType];
        $randomName = Str::uuid()->toString().'.'.$extension;
        $directory = 'media/'.date('Y/m');
        $path = $directory.'/'.$randomName;

        $contents = $this->stripMetadataIfPossible($file->getRealPath(), $mimeType) ?? file_get_contents($file->getRealPath());

        Storage::disk('public')->put($path, $contents);
        $this->ensurePublicDirectoryIsTraversable($directory);
        $this->ensurePublicFileIsReadable($path);

        $media = Media::query()->create([
            'actor_id' => $actor->id,
            'disk' => 'public',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $mimeType,
            'byte_size' => strlen($contents),
            'width' => $width,
            'height' => $height,
            'alt_text' => $altText,
        ]);

        $this->generateThumbnail($media, $mimeType, $width, $height);

        return $media;
    }

    /**
     * Rimuove i metadati EXIF (inclusi eventuali dati di geolocalizzazione)
     * ricodificando l'immagine con GD, quando l'estensione e' disponibile.
     * Se GD non e' installato, il file viene salvato cosi' com'e': una
     * limitazione nota su hosting privi di questa estensione.
     */
    private function stripMetadataIfPossible(string $realPath, string $mimeType): ?string
    {
        if ($mimeType === 'image/gif') {
            return null;
        }

        if (! extension_loaded('gd')) {
            return null;
        }

        $image = $this->loadImage($realPath, $mimeType);

        if ($image === null) {
            return null;
        }

        // Le risorse GdImage vengono deallocate automaticamente dal garbage
        // collector di PHP: imagedestroy() e' un no-op dalla 8.0 e deprecata
        // dalla 8.5, quindi non viene chiamata volutamente.
        ob_start();
        $this->outputImage($image, $mimeType);

        return ob_get_clean() ?: null;
    }

    private function generateThumbnail(Media $media, string $mimeType, int $width, int $height): void
    {
        if ($mimeType === 'image/gif') {
            return;
        }

        if (! extension_loaded('gd')) {
            return;
        }

        if ($width <= self::THUMBNAIL_MAX_DIMENSION && $height <= self::THUMBNAIL_MAX_DIMENSION) {
            return;
        }

        $source = $this->loadImage(Storage::disk($media->disk)->path($media->path), $mimeType);

        if ($source === null) {
            return;
        }

        $ratio = min(self::THUMBNAIL_MAX_DIMENSION / $width, self::THUMBNAIL_MAX_DIMENSION / $height);
        $thumbWidth = max(1, (int) round($width * $ratio));
        $thumbHeight = max(1, (int) round($height * $ratio));

        $thumbnail = $this->resizeImage($source, $width, $height, $thumbWidth, $thumbHeight, $mimeType);

        ob_start();
        $this->outputImage($thumbnail, $mimeType);
        $contents = ob_get_clean();

        if ($contents === false || $contents === '') {
            return;
        }

        $thumbnailPath = preg_replace('/(\.[a-z0-9]+)$/i', '_thumb$1', $media->path) ?? $media->path.'_thumb';

        Storage::disk($media->disk)->put($thumbnailPath, $contents);
        $this->ensurePublicFileIsReadable($thumbnailPath);

        MediaVariant::query()->create([
            'media_id' => $media->id,
            'type' => MediaVariant::TYPE_THUMBNAIL,
            'disk' => $media->disk,
            'path' => $thumbnailPath,
            'width' => $thumbWidth,
            'height' => $thumbHeight,
        ]);
    }
}
