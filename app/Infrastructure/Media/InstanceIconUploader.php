<?php

namespace App\Infrastructure\Media;

use App\Infrastructure\Media\Concerns\ManipulatesImagesWithGd;
use App\Infrastructure\Media\Concerns\NormalizesPublicDiskPermissions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Genera dal file caricato dall'amministratore tutte le varianti di icona
 * dell'istanza: favicon del browser, Apple Touch Icon (iOS) e icone Android
 * (anche maskable) per "Aggiungi alla schermata Home". Il risultato e' una
 * cartella su disco "public"; il percorso resta in {@see \App\Infrastructure\Database\SystemSetting}.
 */
final class InstanceIconUploader
{
    use ManipulatesImagesWithGd, NormalizesPublicDiskPermissions;

    public const MIN_DIMENSION = 180;

    public const DIRECTORY_PREFIX = 'instance-icons';

    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    private const MASTER_SIZE = 512;

    /** @var array<string, int> */
    private const VARIANTS = [
        'favicon-32.png' => 32,
        'apple-touch-icon.png' => 180,
        'icon-192.png' => 192,
        'icon-512.png' => 512,
    ];

    /** @var array<string, int> */
    private const MASKABLE_VARIANTS = [
        'icon-192-maskable.png' => 192,
        'icon-512-maskable.png' => 512,
    ];

    public function store(UploadedFile $file, ?string $previousDirectory): string
    {
        if (! extension_loaded('gd')) {
            throw new InvalidArgumentException(
                'L\'estensione PHP GD e\' necessaria per generare le icone del sito.',
            );
        }

        $mimeType = (string) $file->getMimeType();

        if (! in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
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

        if ($width < self::MIN_DIMENSION || $height < self::MIN_DIMENSION) {
            throw new InvalidArgumentException(
                'L\'immagine deve essere almeno '.self::MIN_DIMENSION.'×'.self::MIN_DIMENSION.' pixel (consigliato 512×512 o piu\').',
            );
        }

        $image = $this->loadImage($file->getRealPath(), $mimeType);

        if ($image === null) {
            throw new InvalidArgumentException('Impossibile elaborare l\'immagine caricata.');
        }

        $square = $this->cropToSquare($image, $width, $height);
        $size = min($width, $height);

        if ($size > self::MASTER_SIZE) {
            $square = $this->resizeImage($square, $size, $size, self::MASTER_SIZE, self::MASTER_SIZE, 'image/png');
            $size = self::MASTER_SIZE;
        }

        $directory = self::DIRECTORY_PREFIX.'/'.Str::uuid()->toString();
        $disk = Storage::disk('public');

        foreach (self::VARIANTS as $filename => $targetSize) {
            $path = $directory.'/'.$filename;
            $disk->put($path, $this->pngAtSize($square, $size, $targetSize));
            $this->ensurePublicFileIsReadable($path);
        }

        foreach (self::MASKABLE_VARIANTS as $filename => $targetSize) {
            $path = $directory.'/'.$filename;
            $disk->put($path, $this->maskablePng($square, $size, $targetSize));
            $this->ensurePublicFileIsReadable($path);
        }

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

    /**
     * @param  \GdImage  $image
     * @return \GdImage
     */
    private function cropToSquare($image, int $width, int $height)
    {
        $size = min($width, $height);
        $srcX = (int) (($width - $size) / 2);
        $srcY = (int) (($height - $size) / 2);
        $square = imagecreatetruecolor($size, $size);

        imagealphablending($square, false);
        imagesavealpha($square, true);
        $transparent = imagecolorallocatealpha($square, 0, 0, 0, 127);
        imagefilledrectangle($square, 0, 0, $size, $size, $transparent);
        imagealphablending($square, true);
        imagecopy($square, $image, 0, 0, $srcX, $srcY, $size, $size);
        imagealphablending($square, false);
        imagesavealpha($square, true);

        return $square;
    }

    /**
     * @param  \GdImage  $square
     */
    private function pngAtSize($square, int $sourceSize, int $targetSize): string
    {
        $image = $this->resizeImage($square, $sourceSize, $sourceSize, $targetSize, $targetSize, 'image/png');

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * Icona Android "maskable": il contenuto sta nella safe zone centrale
     * (circa l'80% del canvas), il resto e' riempito con un colore di fondo
     * cosi' il ritaglio a cerchio/squircle di Chrome non taglia il logo.
     *
     * @param  \GdImage  $square
     */
    private function maskablePng($square, int $sourceSize, int $canvasSize): string
    {
        $iconSize = (int) round($canvasSize * 0.8);
        $offset = (int) round(($canvasSize - $iconSize) / 2);
        [$red, $green, $blue] = $this->backgroundColor($square);

        $canvas = imagecreatetruecolor($canvasSize, $canvasSize);
        imagealphablending($canvas, true);
        imagesavealpha($canvas, false);
        imagefilledrectangle(
            $canvas,
            0,
            0,
            $canvasSize,
            $canvasSize,
            imagecolorallocate($canvas, $red, $green, $blue),
        );

        $icon = $this->resizeImage($square, $sourceSize, $sourceSize, $iconSize, $iconSize, 'image/png');
        imagecopy($canvas, $icon, $offset, $offset, 0, 0, $iconSize, $iconSize);

        ob_start();
        imagepng($canvas);

        return (string) ob_get_clean();
    }

    /**
     * @param  \GdImage  $image
     * @return array{0: int, 1: int, 2: int}
     */
    private function backgroundColor($image): array
    {
        $sample = imagecolorat($image, 0, 0);
        $alpha = ($sample >> 24) & 0x7F;

        if ($alpha > 60) {
            return [24, 119, 242];
        }

        return [
            ($sample >> 16) & 0xFF,
            ($sample >> 8) & 0xFF,
            $sample & 0xFF,
        ];
    }
}
