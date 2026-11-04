<?php

namespace App\Infrastructure\Media;

use App\Infrastructure\Database\SystemSetting;
use App\Infrastructure\Media\Concerns\ManipulatesImagesWithGd;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Sfondo della homepage ospite: conserva il rapporto d'aspetto, riduce i
 * lati lunghi e scrive un JPEG sul disco selezionato. Percorso e disco
 * restano in {@see SystemSetting}.
 */
final class HomeBackgroundUploader
{
    use ManipulatesImagesWithGd;

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

    public function store(UploadedFile $file, ?string $previousDirectory, string $previousDisk = 'public', ?string $destination = null): string
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
        $destination ??= app(MediaStorage::class)->uploadDisk();

        ob_start();
        imagejpeg($image, null, 86);
        app(AssetStorage::class)->store($destination, [$path => (string) ob_get_clean()]);
        $this->deleteDirectory($previousDirectory, $previousDisk);

        return $directory;
    }

    public function deleteDirectory(?string $directory, string $disk = 'public'): void
    {
        if (! filled($directory) || ! self::isValidDirectory($directory)) {
            return;
        }

        app(AssetStorage::class)->removeAfterCommit($disk, [$directory.'/'.self::FILENAME]);
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
