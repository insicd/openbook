<?php

namespace App\Infrastructure\Media;

use App\Infrastructure\Media\Concerns\ManipulatesImagesWithGd;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Gestisce il caricamento dell'avatar e dell'immagine di copertina di un
 * profilo: stessa validazione del tipo effettivo del file e rimozione dei
 * metadati EXIF di {@see MediaUploader}, ma qui il risultato e' un semplice
 * percorso su disco (salvato in "profiles.avatar_path"/"cover_path"), non un
 * record "Media" a se stante come per gli allegati dei post. Il file
 * precedente, se presente, viene rimosso per non accumulare copie orfane.
 */
final class ProfileImageUploader
{
    use ManipulatesImagesWithGd;

    private const ALLOWED_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    private const AVATAR_MAX_DIMENSION = 512;

    private const COVER_MAX_DIMENSION = 1600;

    public function storeAvatar(UploadedFile $file, ?string $previousPath, string $previousDisk = 'public', ?string $disk = null): string
    {
        return $this->store($file, $previousPath, 'avatars', self::AVATAR_MAX_DIMENSION, $previousDisk, $disk);
    }

    public function storeCover(UploadedFile $file, ?string $previousPath, string $previousDisk = 'public', ?string $disk = null): string
    {
        return $this->store($file, $previousPath, 'covers', self::COVER_MAX_DIMENSION, $previousDisk, $disk);
    }

    private function store(UploadedFile $file, ?string $previousPath, string $directory, int $maxDimension, string $previousDisk, ?string $disk): string
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
        $path = $directory.'/'.Str::uuid()->toString().'.'.$extension;

        $contents = $this->prepareContents($file->getRealPath(), $mimeType, $width, $height, $maxDimension)
            ?? file_get_contents($file->getRealPath());

        $disk ??= app(MediaStorage::class)->uploadDisk();
        app(AssetStorage::class)->store($disk, [$path => $contents]);
        if (filled($previousPath)) {
            app(AssetStorage::class)->removeAfterCommit($previousDisk, [$previousPath]);
        }

        return $path;
    }

    /**
     * Ricodifica sempre l'immagine con GD (quando disponibile): rimuove i
     * metadati EXIF e, se supera la dimensione massima consentita per quel
     * tipo di immagine (avatar o copertina), la ridimensiona per non
     * occupare piu' spazio del necessario su hosting con quota limitata.
     */
    private function prepareContents(string $realPath, string $mimeType, int $width, int $height, int $maxDimension): ?string
    {
        if (! extension_loaded('gd')) {
            return null;
        }

        $image = $this->loadImage($realPath, $mimeType);

        if ($image === null) {
            return null;
        }

        if ($width > $maxDimension || $height > $maxDimension) {
            $ratio = min($maxDimension / $width, $maxDimension / $height);
            $targetWidth = max(1, (int) round($width * $ratio));
            $targetHeight = max(1, (int) round($height * $ratio));
            $image = $this->resizeImage($image, $width, $height, $targetWidth, $targetHeight, $mimeType);
        }

        ob_start();
        $this->outputImage($image, $mimeType);

        return ob_get_clean() ?: null;
    }
}
