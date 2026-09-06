<?php

namespace App\Infrastructure\Media;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/** Verifica i binari video configurati senza passare da una shell. */
final class VideoCapability
{
    /**
     * @return array{available: bool, ffmpeg_version: ?string, ffprobe_version: ?string, error: ?string}
     */
    public function inspect(string $ffmpegPath, string $ffprobePath): array
    {
        try {
            $ffmpegVersion = $this->version($ffmpegPath, 'ffmpeg');
            $ffprobeVersion = $this->version($ffprobePath, 'ffprobe');

            return [
                'available' => true,
                'ffmpeg_version' => $ffmpegVersion,
                'ffprobe_version' => $ffprobeVersion,
                'error' => null,
            ];
        } catch (\Throwable $exception) {
            return [
                'available' => false,
                'ffmpeg_version' => null,
                'ffprobe_version' => null,
                'error' => $exception->getMessage(),
            ];
        }
    }

    private function version(string $binary, string $expectedName): string
    {
        if (trim($binary) === '') {
            throw new \RuntimeException('Percorso del binario vuoto.');
        }

        $process = new Process([$binary, '-version']);
        $process->setTimeout(5);

        try {
            $process->mustRun();
        } catch (ProcessFailedException|ProcessTimedOutException $exception) {
            throw new \RuntimeException($exception->getMessage(), previous: $exception);
        }

        $firstLine = strtok(trim($process->getOutput()), "\r\n");

        if (! is_string($firstLine) || $firstLine === '') {
            throw new \RuntimeException("{$binary} non ha restituito una versione valida.");
        }

        if (! str_starts_with(strtolower($firstLine), $expectedName.' version ')) {
            throw new \RuntimeException("{$binary} non sembra essere {$expectedName}.");
        }

        return mb_substr($firstLine, 0, 255);
    }
}
