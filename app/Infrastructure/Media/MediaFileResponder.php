<?php

namespace App\Infrastructure\Media;

use Aws\Exception\AwsException;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class MediaFileResponder
{
    public function respond(Request $request, Media $media, ?string $variant): Response
    {
        abort_if($media->isRemote() || ! preg_match('~^(image|audio|video)/~', $media->mime_type), 404);
        $file = $variant === 'thumbnail' ? $media->thumbnail : $media;
        abort_unless($file !== null && $file->disk === MediaStorage::OBJECT_DISK, 404);
        $mime = $variant !== null && $media->isVideo() ? 'image/jpeg' : $media->mime_type;

        return $this->fileResponse($request, $file->disk, $file->path, $mime, $variant === null && ($media->isAudio() || $media->isVideo()), $media->id);
    }

    public function imageResponse(Request $request, string $disk, string $path, string $mime, string $identifier): Response
    {
        return $this->fileResponse($request, $disk, $path, $mime, false, $identifier);
    }

    private function fileResponse(Request $request, string $diskName, string $path, string $mime, bool $supportsRanges, string $identifier): Response
    {
        $headers = ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=3600'];
        $length = null;
        $range = null;

        try {
            $disk = Storage::disk($diskName);
            if ($supportsRanges) {
                $size = $disk->size($path);
                $headers['Accept-Ranges'] = 'bytes';
                // We advertise no validator. If-Range cannot be matched, so
                // conservatively return the full representation in that case.
                if ($request->isMethod('GET') && ! $request->headers->has('If-Range')) {
                    $range = $this->range($request->header('Range'), $size);
                }
                if ($range === false) {
                    return new Response('', 416, array_merge($headers, ['Content-Range' => 'bytes */'.$size, 'Content-Length' => '0', 'Cache-Control' => 'no-store']));
                }
                $length = $range === null ? $size : $range[1] - $range[0] + 1;
                $headers['Content-Length'] = (string) $length;
                if ($range !== null) {
                    $headers['Content-Range'] = "bytes {$range[0]}-{$range[1]}/{$size}";
                }
                if ($request->isMethod('HEAD')) {
                    return new Response('', 200, $headers);
                }
            }
            if ($range !== null && $disk instanceof AwsS3V3Adapter) {
                $result = $disk->getClient()->getObject([
                    'Bucket' => $disk->getConfig()['bucket'], 'Key' => $path,
                    'Range' => "bytes={$range[0]}-{$range[1]}", '@http' => ['stream' => true],
                ]);
                $body = $result['Body'];
                if ($result['ContentRange'] !== $headers['Content-Range']) {
                    $body->close();
                    throw new \RuntimeException;
                }
                $stream = $body->detach();
            } else {
                $stream = $disk->readStream($path);
                if ($range !== null && is_resource($stream) && fseek($stream, $range[0]) !== 0) {
                    fclose($stream);
                    throw new \RuntimeException;
                }
            }
        } catch (Throwable $exception) {
            $this->storageError($exception);
        }
        abort_unless(is_resource($stream), 404);
        if ($request->isMethod('HEAD')) {
            fclose($stream);

            return new Response('', 200, $headers);
        }

        return new StreamedResponse(function () use ($stream, $length, $identifier): void {
            $remaining = $length;
            try {
                while (! feof($stream) && ($remaining === null || $remaining > 0)) {
                    $bytes = fread($stream, $remaining === null ? 65536 : min(65536, $remaining));
                    if ($bytes === false || $bytes === '') {
                        break;
                    }
                    echo $bytes;
                    if ($remaining !== null) {
                        $remaining -= strlen($bytes);
                    }
                    if (connection_aborted()) {
                        return;
                    }
                }
                if ($remaining !== null && $remaining > 0) {
                    throw new \RuntimeException;
                }
            } catch (Throwable) {
                // Headers may already be sent. Leave a truncated response (the
                // Content-Length lets the player detect it), never an SDK trace.
                Log::warning('Media stream interrupted.', ['media_id' => $identifier]);
            } finally {
                fclose($stream);
            }
        }, $range === null ? 200 : 206, $headers);
    }

    /** @return array{int, int}|false|null */
    private function range(?string $header, int $size): array|false|null
    {
        // Multiple or malformed ranges are ignored; no multipart machinery.
        if ($header === null || ! preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $parts) || ($parts[1] === '' && $parts[2] === '')) {
            return null;
        }
        if ($size === 0) {
            return false;
        }
        if ($parts[1] === '') {
            $suffix = (int) $parts[2];

            return $suffix > 0 ? [max(0, $size - $suffix), $size - 1] : false;
        }
        $start = (int) $parts[1];
        $end = $parts[2] === '' ? $size - 1 : min((int) $parts[2], $size - 1);

        return $start >= $size || $end < $start ? false : [$start, $end];
    }

    private function storageError(Throwable $exception): never
    {
        do {
            if ($exception instanceof AwsException && $exception->getStatusCode() === 404) {
                abort(404);
            }
        } while ($exception = $exception->getPrevious());
        abort(503);
    }
}
