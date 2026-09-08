<?php

namespace App\Infrastructure\Media;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/** Analizza un video locale con ffprobe senza passare da una shell. */
final class VideoProbe
{
    /**
     * @return array{container: string, duration_ms: int, width: int, height: int, frame_rate: float, video_codec: string, audio_codec: ?string, pixel_format: ?string}
     */
    public function inspect(string $path): array
    {
        $process = new Process([
            (string) config('openbook.video.ffprobe_path', 'ffprobe'),
            '-v', 'error',
            '-show_entries', 'format=format_name,duration:stream=codec_type,codec_name,pix_fmt,width,height,avg_frame_rate,r_frame_rate',
            '-of', 'json',
            $path,
        ]);
        $process->setTimeout(15);

        try {
            $process->mustRun();
        } catch (ProcessFailedException|ProcessTimedOutException $exception) {
            throw new \InvalidArgumentException('Il video non puo\' essere analizzato da ffprobe.', previous: $exception);
        }

        $data = json_decode($process->getOutput(), true);

        if (! is_array($data)) {
            throw new \InvalidArgumentException('ffprobe ha restituito dati non validi.');
        }

        $streams = is_array($data['streams'] ?? null) ? $data['streams'] : [];
        $video = collect($streams)->first(fn ($stream) => is_array($stream) && ($stream['codec_type'] ?? null) === 'video');
        $audio = collect($streams)->first(fn ($stream) => is_array($stream) && ($stream['codec_type'] ?? null) === 'audio');
        $duration = filter_var($data['format']['duration'] ?? null, FILTER_VALIDATE_FLOAT);
        $width = filter_var($video['width'] ?? null, FILTER_VALIDATE_INT);
        $height = filter_var($video['height'] ?? null, FILTER_VALIDATE_INT);

        if (! is_array($video) || $duration === false || $duration <= 0 || $width === false || $width <= 0 || $height === false || $height <= 0) {
            throw new \InvalidArgumentException('Il file non contiene un flusso video valido.');
        }

        $frameRate = $this->frameRate((string) ($video['avg_frame_rate'] ?? $video['r_frame_rate'] ?? ''));

        if ($frameRate <= 0) {
            $frameRate = $this->frameRate((string) ($video['r_frame_rate'] ?? ''));
        }

        if ($frameRate <= 0) {
            throw new \InvalidArgumentException('Il frame rate del video non e\' valido.');
        }

        return [
            'container' => (string) ($data['format']['format_name'] ?? ''),
            'duration_ms' => (int) round($duration * 1000),
            'width' => (int) $width,
            'height' => (int) $height,
            'frame_rate' => round($frameRate, 3),
            'video_codec' => strtolower((string) ($video['codec_name'] ?? '')),
            'audio_codec' => is_array($audio) ? strtolower((string) ($audio['codec_name'] ?? '')) ?: null : null,
            'pixel_format' => isset($video['pix_fmt']) ? strtolower((string) $video['pix_fmt']) : null,
        ];
    }

    private function frameRate(string $value): float
    {
        if (preg_match('/^(\d+(?:\.\d+)?)(?:\/(\d+(?:\.\d+)?))?$/', trim($value), $matches) !== 1) {
            return 0.0;
        }

        $numerator = (float) $matches[1];
        $denominator = isset($matches[2]) ? (float) $matches[2] : 1.0;

        return $denominator > 0 ? $numerator / $denominator : 0.0;
    }
}
