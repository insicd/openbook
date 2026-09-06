<?php

namespace App\Infrastructure\Media;

use App\Domain\Posts\PendingPostAttachment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/** Prepara il file MP4 canonico e il poster di un video in staging. */
final class VideoPreparer
{
    public function __construct(private readonly VideoProbe $videoProbe) {}

    public function prepare(PendingPostAttachment $attachment): PreparedMedia
    {
        if ($attachment->media_type !== 'video') {
            return new PreparedMedia(
                $attachment->disk,
                $attachment->path,
                (string) $attachment->original_name,
                $attachment->mime_type,
                $attachment->byte_size,
                $attachment->width,
                $attachment->height,
                $attachment->alt_text,
            );
        }

        $disk = Storage::disk($attachment->disk);
        $sourcePath = $disk->path($attachment->path);

        if (! $disk->exists($attachment->path)) {
            throw new \RuntimeException('Il file video sorgente non esiste piu\'.');
        }

        $directory = dirname($attachment->path);
        $preparedPath = $attachment->processing === PendingPostAttachment::PROCESS_TRANSCODE
            ? $directory.'/prepared-'.Str::uuid().'.mp4'
            : $attachment->path;
        $posterPath = $directory.'/poster-'.Str::uuid().'.jpg';

        try {
            if ($preparedPath !== $attachment->path) {
                $this->transcode($sourcePath, $disk->path($preparedPath), $attachment);
            }

            $metadata = $this->videoProbe->inspect($disk->path($preparedPath));
            $this->createPoster($disk->path($preparedPath), $disk->path($posterPath));
            $byteSize = $disk->size($preparedPath);

            if (! is_int($byteSize) || $byteSize <= 0) {
                throw new \RuntimeException('Impossibile determinare la dimensione del video preparato.');
            }

            return new PreparedMedia(
                $attachment->disk,
                $preparedPath,
                (string) $attachment->original_name,
                'video/mp4',
                $byteSize,
                $metadata['width'],
                $metadata['height'],
                $attachment->alt_text,
                $posterPath,
            );
        } catch (\Throwable $exception) {
            if ($preparedPath !== $attachment->path) {
                $disk->delete($preparedPath);
            }

            $disk->delete($posterPath);

            throw $exception;
        }
    }

    private function transcode(string $source, string $destination, PendingPostAttachment $attachment): void
    {
        $maxDimension = (int) config('openbook.video.max_dimension');
        $maxFrameRate = (int) config('openbook.video.max_frame_rate');
        $filters = [
            "scale='min({$maxDimension},iw)':'min({$maxDimension},ih)':force_original_aspect_ratio=decrease:force_divisible_by=2",
        ];

        if ((float) $attachment->frame_rate > $maxFrameRate) {
            $filters[] = 'fps='.$maxFrameRate;
        }

        $this->run([
            (string) config('openbook.video.ffmpeg_path', 'ffmpeg'),
            '-y',
            '-i', $source,
            '-map', '0:v:0',
            '-map', '0:a:0?',
            '-map_metadata', '-1',
            '-vf', implode(',', $filters),
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-crf', '23',
            '-pix_fmt', 'yuv420p',
            '-c:a', 'aac',
            '-b:a', '128k',
            '-movflags', '+faststart',
            $destination,
        ], 'La transcodifica del video non e\' riuscita.');
    }

    private function createPoster(string $source, string $destination): void
    {
        $this->run([
            (string) config('openbook.video.ffmpeg_path', 'ffmpeg'),
            '-y',
            '-ss', '0',
            '-i', $source,
            '-frames:v', '1',
            '-vf', "scale='min(640,iw)':'min(640,ih)':force_original_aspect_ratio=decrease:force_divisible_by=2",
            '-q:v', '3',
            $destination,
        ], 'La generazione dell\'anteprima video non e\' riuscita.');
    }

    /** @param list<string> $command */
    private function run(array $command, string $error): void
    {
        $process = new Process($command);
        $process->setTimeout((int) config('openbook.video.process_timeout_seconds', 900));

        try {
            $process->mustRun();
        } catch (ProcessFailedException|ProcessTimedOutException $exception) {
            throw new \RuntimeException($error, previous: $exception);
        }
    }
}
