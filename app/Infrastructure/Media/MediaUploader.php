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
 * Gestisce il caricamento di un allegato media (immagine o audio) legato a un
 * post: valida il tipo effettivo del file (mai la sola estensione), genera
 * un nome casuale e, per le immagini, rimuove i metadati EXIF sensibili e
 * produce una miniatura in modo sincrono con GD.
 */
final class MediaUploader
{
    use ManipulatesImagesWithGd, NormalizesPublicDiskPermissions;

    private const IMAGE_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    private const AUDIO_EXTENSIONS = [
        'audio/mpeg' => 'mp3',
        'audio/ogg' => 'ogg',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'audio/flac' => 'flac',
        'audio/webm' => 'webm',
        'audio/aac' => 'aac',
    ];

    private const THUMBNAIL_MAX_DIMENSION = 640;

    public function store(UploadedFile $file, Actor $actor, ?string $altText = null): Media
    {
        $mimeType = (string) $file->getMimeType();

        if (str_starts_with($mimeType, 'audio/')) {
            return $this->storeAudio($file, $actor, $mimeType, $altText);
        }

        return $this->storeImage($file, $actor, $mimeType, $altText);
    }

    public function storePrepared(PreparedMedia $prepared, Actor $actor): Media
    {
        if (! str_starts_with($prepared->mimeType, 'video/')) {
            $file = new UploadedFile(
                Storage::disk($prepared->disk)->path($prepared->path),
                $prepared->originalName,
                $prepared->mimeType,
                UPLOAD_ERR_OK,
                true,
            );

            return $this->store($file, $actor, $prepared->altText);
        }

        $source = Storage::disk($prepared->disk);

        if (! $source->exists($prepared->path)) {
            throw new InvalidArgumentException('Il file video preparato non esiste.');
        }

        $disk = app(MediaStorage::class)->uploadDisk();
        $directory = 'media/'.date('Y/m');
        $path = $directory.'/'.Str::uuid().'.mp4';
        $thumbnailPath = $prepared->thumbnailPath === null ? null : $directory.'/'.Str::uuid().'_thumb.jpg';

        return $this->storeFiles($disk, array_values(array_filter([$path, $thumbnailPath])), function () use ($source, $prepared, $actor, $disk, $directory, $path, $thumbnailPath): Media {
            $this->copyFile($prepared->disk, $prepared->path, $disk, $path);
            if ($disk === 'public') {
                $this->ensurePublicDirectoryIsTraversable($directory);
                $this->ensurePublicFileIsReadable($path);
            }
            $media = Media::query()->create([
                'actor_id' => $actor->id,
                'disk' => $disk,
                'path' => $path,
                'original_name' => $prepared->originalName,
                'mime_type' => 'video/mp4',
                'byte_size' => $source->size($prepared->path),
                'width' => $prepared->width,
                'height' => $prepared->height,
                'alt_text' => $prepared->altText,
            ]);
            if ($thumbnailPath !== null) {
                $dimensions = @getimagesize($source->path($prepared->thumbnailPath));
                if ($dimensions === false) {
                    throw new \RuntimeException;
                }
                $this->copyFile($prepared->disk, $prepared->thumbnailPath, $disk, $thumbnailPath);
                if ($disk === 'public') {
                    $this->ensurePublicFileIsReadable($thumbnailPath);
                }
                MediaVariant::query()->create([
                    'media_id' => $media->id,
                    'type' => MediaVariant::TYPE_THUMBNAIL,
                    'disk' => $disk,
                    'path' => $thumbnailPath,
                    'width' => $dimensions[0],
                    'height' => $dimensions[1],
                ]);
            }

            return $media;
        });
    }

    private function storeImage(UploadedFile $file, Actor $actor, string $mimeType, ?string $altText): Media
    {
        $allowedMimeTypes = (array) config('openbook.media.allowed_mime_types');

        if (! in_array($mimeType, $allowedMimeTypes, true) || ! isset(self::IMAGE_EXTENSIONS[$mimeType])) {
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

        $extension = self::IMAGE_EXTENSIONS[$mimeType];
        $randomName = Str::uuid()->toString().'.'.$extension;
        $directory = 'media/'.date('Y/m');
        $path = $directory.'/'.$randomName;

        $contents = $this->stripMetadataIfPossible($file->getRealPath(), $mimeType) ?? file_get_contents($file->getRealPath());

        if ($contents === false) {
            throw new InvalidArgumentException('Impossibile leggere il file immagine.');
        }
        $disk = app(MediaStorage::class)->uploadDisk();
        $thumbnailPath = preg_replace('/(\.[a-z0-9]+)$/i', '_thumb$1', $path) ?? $path.'_thumb';

        return $this->storeFiles($disk, [$path, $thumbnailPath], function () use ($file, $actor, $disk, $directory, $path, $contents, $mimeType, $width, $height, $altText): Media {
            if (! Storage::disk($disk)->put($path, $contents)) {
                throw new \RuntimeException;
            }
            if ($disk === 'public') {
                $this->ensurePublicDirectoryIsTraversable($directory);
                $this->ensurePublicFileIsReadable($path);
            }

            $media = Media::query()->create([
                'actor_id' => $actor->id,
                'disk' => $disk,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $mimeType,
                'byte_size' => strlen($contents),
                'width' => $width,
                'height' => $height,
                'alt_text' => $altText,
            ]);
            $this->generateThumbnail($media, $file->getRealPath(), $mimeType, $width, $height);

            return $media;
        });
    }

    private function storeAudio(UploadedFile $file, Actor $actor, string $mimeType, ?string $altText): Media
    {
        $allowedMimeTypes = (array) config('openbook.media.allowed_mime_types');

        if (! in_array($mimeType, $allowedMimeTypes, true) || ! isset(self::AUDIO_EXTENSIONS[$mimeType])) {
            throw new InvalidArgumentException("Tipo di file non consentito: {$mimeType}.");
        }

        $maxBytes = (int) config('openbook.media.max_size_kb') * 1024;

        if ($file->getSize() === false || $file->getSize() > $maxBytes) {
            throw new InvalidArgumentException('Il file supera la dimensione massima consentita.');
        }

        $extension = self::AUDIO_EXTENSIONS[$mimeType];
        $randomName = Str::uuid()->toString().'.'.$extension;
        $directory = 'media/'.date('Y/m');
        $path = $directory.'/'.$randomName;
        $disk = app(MediaStorage::class)->uploadDisk();

        return $this->storeFiles($disk, [$path], function () use ($file, $actor, $mimeType, $altText, $disk, $directory, $path): Media {
            $stream = fopen($file->getRealPath(), 'rb');
            if ($stream === false) {
                throw new \RuntimeException;
            }
            try {
                if (! Storage::disk($disk)->put($path, $stream)) {
                    throw new \RuntimeException;
                }
            } finally {
                fclose($stream);
            }
            if ($disk === 'public') {
                $this->ensurePublicDirectoryIsTraversable($directory);
                $this->ensurePublicFileIsReadable($path);
            }

            return Media::query()->create([
                'actor_id' => $actor->id,
                'disk' => $disk,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $mimeType,
                'byte_size' => filesize($file->getRealPath()),
                'width' => null,
                'height' => null,
                'alt_text' => $altText,
            ]);
        });
    }

    /** @param list<string> $paths */
    private function storeFiles(string $disk, array $paths, \Closure $store): Media
    {
        return app(MediaStorage::class)->storeFiles($disk, $paths, $store);
    }

    private function copyFile(string $sourceDisk, string $sourcePath, string $disk, string $path): void
    {
        $stream = Storage::disk($sourceDisk)->readStream($sourcePath);
        if (! is_resource($stream)) {
            throw new \RuntimeException;
        }
        try {
            if (! Storage::disk($disk)->put($path, $stream)) {
                throw new \RuntimeException;
            }
        } finally {
            fclose($stream);
        }
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

    private function generateThumbnail(Media $media, string $sourcePath, string $mimeType, int $width, int $height): void
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

        $source = $this->loadImage($sourcePath, $mimeType);

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

        if (! Storage::disk($media->disk)->put($thumbnailPath, $contents)) {
            throw new \RuntimeException;
        }
        if ($media->disk === 'public') {
            $this->ensurePublicFileIsReadable($thumbnailPath);
        }

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
