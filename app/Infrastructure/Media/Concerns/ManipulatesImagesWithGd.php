<?php

namespace App\Infrastructure\Media\Concerns;

use RuntimeException;

/**
 * Operazioni di base su immagini tramite l'estensione GD, condivise da tutti
 * i servizi che salvano immagini caricate dall'utente (allegati dei post,
 * avatar, copertine): caricamento da percorso, ridimensionamento e
 * ricodifica. Isolata in un trait perche' e' pura manipolazione bitmap,
 * senza alcuna conoscenza di dove il risultato verra' salvato.
 */
trait ManipulatesImagesWithGd
{
    /**
     * @return \GdImage|null
     */
    private function loadImage(string $path, string $mimeType)
    {
        return match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($path) ?: null,
            'image/png' => @imagecreatefrompng($path) ?: null,
            'image/webp' => @imagecreatefromwebp($path) ?: null,
            'image/gif' => @imagecreatefromgif($path) ?: null,
            default => null,
        };
    }

    private function outputImage($image, string $mimeType): void
    {
        match ($mimeType) {
            'image/jpeg' => imagejpeg($image, null, 88),
            'image/png' => imagepng($image),
            'image/webp' => imagewebp($image, null, 88),
            'image/gif' => imagegif($image),
            default => throw new RuntimeException("Formato immagine non gestito: {$mimeType}."),
        };
    }

    /**
     * @return \GdImage
     */
    private function resizeImage($image, int $sourceWidth, int $sourceHeight, int $targetWidth, int $targetHeight, string $mimeType)
    {
        $resized = imagecreatetruecolor($targetWidth, $targetHeight);

        if ($mimeType === 'image/png' || $mimeType === 'image/gif') {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
        }

        imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

        return $resized;
    }
}
