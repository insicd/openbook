<?php

namespace App\Infrastructure\Media;

/** Reads the primary image orientation from JPEG EXIF without requiring ext-exif. */
final class JpegExifOrientation
{
    public static function read(string $path): int
    {
        $file = @fopen($path, 'rb');
        if ($file === false) {
            return 1;
        }

        try {
            if (fread($file, 2) !== "\xff\xd8") {
                return 1;
            }

            // Only inspect JPEG segments before the image scan. APP1 can also carry XMP.
            while (fread($file, 1) === "\xff") {
                do {
                    $marker = fread($file, 1);
                } while ($marker === "\xff");

                if (in_array($marker, ['', false, "\0", "\xda", "\xd9"], true)) {
                    break;
                }

                $lengthBytes = fread($file, 2);
                if ($lengthBytes === false || strlen($lengthBytes) !== 2) {
                    break;
                }

                $length = unpack('n', $lengthBytes)[1] - 2;
                if ($length < 0) {
                    break;
                }

                if ($marker === "\xe1" && $length >= 6) {
                    $segment = fread($file, $length);
                    if ($segment !== false && str_starts_with($segment, "Exif\0\0")) {
                        return self::readTiff(substr($segment, 6));
                    }
                } elseif (fseek($file, $length, SEEK_CUR) !== 0) {
                    break;
                }
            }
        } finally {
            fclose($file);
        }

        return 1;
    }

    private static function readTiff(string $tiff): int
    {
        if (strlen($tiff) < 8) {
            return 1;
        }

        [$short, $long] = match (substr($tiff, 0, 4)) {
            "II*\0" => ['v', 'V'],
            "MM\0*" => ['n', 'N'],
            default => [null, null],
        };
        if ($short === null) {
            return 1;
        }

        $directory = unpack($long, $tiff, 4)[1];
        if ($directory < 8 || $directory > strlen($tiff) - 2) {
            return 1;
        }

        $count = unpack($short, $tiff, $directory)[1];
        $start = $directory + 2;
        $end = $start + 12 * $count;
        if ($end > strlen($tiff)) {
            return 1;
        }

        for ($offset = $start; $offset < $end; $offset += 12) {
            $entry = unpack("{$short}tag/{$short}type/{$long}count/{$short}value", $tiff, $offset);
            if ($entry['tag'] === 0x0112 && $entry['type'] === 3 && $entry['count'] === 1) {
                return $entry['value'] >= 1 && $entry['value'] <= 8 ? $entry['value'] : 1;
            }
        }

        return 1;
    }
}
