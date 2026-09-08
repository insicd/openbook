<?php

namespace Tests\Unit\Infrastructure\Media;

use App\Infrastructure\Media\VideoCapability;
use Tests\TestCase;

class VideoCapabilityTest extends TestCase
{
    public function test_it_detects_available_ffmpeg_tools(): void
    {
        $directory = $this->fakeTools();

        $status = app(VideoCapability::class)->inspect(
            $directory.'/ffmpeg',
            $directory.'/ffprobe',
        );

        $this->assertTrue($status['available']);
        $this->assertSame('ffmpeg version test-1.0', $status['ffmpeg_version']);
        $this->assertSame('ffprobe version test-1.0', $status['ffprobe_version']);
    }

    public function test_it_rejects_missing_or_unexpected_tools(): void
    {
        $missing = app(VideoCapability::class)->inspect('/missing/ffmpeg', '/missing/ffprobe');
        $wrong = app(VideoCapability::class)->inspect(PHP_BINARY, PHP_BINARY);

        $this->assertFalse($missing['available']);
        $this->assertFalse($wrong['available']);
    }

    private function fakeTools(): string
    {
        $directory = storage_path('framework/testing/video-tools-'.uniqid());
        mkdir($directory, 0777, true);

        foreach (['ffmpeg', 'ffprobe'] as $tool) {
            $path = $directory.'/'.$tool;
            file_put_contents($path, "#!/bin/sh\nprintf '{$tool} version test-1.0\\n'\n");
            chmod($path, 0755);
        }

        return $directory;
    }
}
