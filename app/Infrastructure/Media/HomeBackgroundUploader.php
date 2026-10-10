<?php

namespace App\Infrastructure\Media;

use App\Infrastructure\Media\Concerns\ManipulatesImagesWithGd;
use App\Infrastructure\Media\Concerns\NormalizesPublicDiskPermissions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Sfondo della homepage ospite: conserva il rapporto d'aspetto, riduce i
 * lati lunghi e scrive un JPEG sul disco public. Il percorso della cartella
 * resta in {@see \App\Infrastructure\Database\SystemSetting}.
 */
final class HomeBackgroundUploader
{
    use ManipulatesImagesWithGd, NormalizesPublicDiskPermissions;

    public const DIRECTORY_PREFIX = 'home-backgrounds';

    public const FILENAME = 'background.jpg';

    public const MIN_WIDTH = 640;

    public const MIN_HEIGHT = 360;

    public const MAX_EDGE = 1920;

    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    public function store(UploadedFile $file, ?string $previousDirectory): string
    {
        if (! extension_loaded('gd')) {
            throw new InvalidArgumentException(
                'L\'estensione PHP GD e\' necessaria per preparare lo sfondo della homepage.',
            );
        }

        $mimeType = (string) $file->getMimeType();

        if (! in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new InvalidArgumentException("Tipo di file non consentito: {$mimeType}.");
        }

        $maxBytes = max((int) config('openbook.media.max_size_kb') * 1024, 8 * 1024 * 1024);

        if ($file->getSize() === false || $file->getSize() > $maxBytes) {
            throw new InvalidArgumentException('Il file supera la dimensione massima consentita.');
        }

        $dimensions = @getimagesize($file->getRealPath());

        if ($dimensions === false) {
            throw new InvalidArgumentException('Il file non e un\'immagine valida.');
        }

        [$width, $height] = $dimensions;

        if ($width < self::MIN_WIDTH || $height < self::MIN_HEIGHT) {
            throw new InvalidArgumentException(
                'L\'immagine deve essere almeno '.self::MIN_WIDTH.'×'.self::MIN_HEIGHT.' pixel.',
            );
        }

        $image = $this->loadImage($file->getRealPath(), $mimeType);

        if ($image === null) {
            throw new InvalidArgumentException('Impossibile elaborare l\'immagine caricata.');
        }

        $longest = max($width, $height);

        if ($longest > self::MAX_EDGE) {
            $scale = self::MAX_EDGE / $longest;
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));
            $image = $this->resizeImage($image, $width, $height, $targetWidth, $targetHeight, 'image/jpeg');
        }

        $directory = self::DIRECTORY_PREFIX.'/'.Str::uuid()->toString();
        $path = $directory.'/'.self::FILENAME;
        $disk = Storage::disk('public');

        ob_start();
        imagejpeg($image, null, 86);
        $disk->put($path, (string) ob_get_clean());
        $this->ensurePublicFileIsReadable($path);
        $this->ensurePublicDirectoryIsTraversable($directory);
        $this->deleteDirectory($previousDirectory);

        return $directory;
    }

    public function deleteDirectory(?string $directory): void
    {
        if (! filled($directory) || ! self::isValidDirectory($directory)) {
            return;
        }

        Storage::disk('public')->deleteDirectory($directory);
    }

    public static function isValidDirectory(?string $directory): bool
    {
        if ($directory === null) {
            return false;
        }

        return (bool) preg_match(
            '#^'.preg_quote(self::DIRECTORY_PREFIX, '#').'/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$#',
            $directory,
        );
    }
}
