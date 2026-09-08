<?php

namespace Tests\Unit\Infrastructure\Media;

use App\Infrastructure\Media\VideoProbe;
use InvalidArgumentException;
use Tests\TestCase;

class VideoProbeTest extends TestCase
{
    public function test_it_extracts_normalized_video_metadata(): void
    {
        config(['openbook.video.ffprobe_path' => $this->fakeProbe(json_encode([
            'streams' => [
                [
                    'codec_type' => 'video',
                    'codec_name' => 'h264',
                    'pix_fmt' => 'yuv420p',
                    'width' => 1080,
                    'height' => 1920,
                    'avg_frame_rate' => '30000/1001',
                ],
                ['codec_type' => 'audio', 'codec_name' => 'aac'],
            ],
            'format' => ['format_name' => 'mov,mp4,m4a,3gp,3g2,mj2', 'duration' => '12.345'],
        ], JSON_THROW_ON_ERROR))]);

        $metadata = app(VideoProbe::class)->inspect(__FILE__);

        $this->assertSame('h264', $metadata['video_codec']);
        $this->assertSame('aac', $metadata['audio_codec']);
        $this->assertSame(1080, $metadata['width']);
        $this->assertSame(1920, $metadata['height']);
        $this->assertSame(29.97, $metadata['frame_rate']);
        $this->assertSame(12345, $metadata['duration_ms']);
    }

    public function test_it_rejects_output_without_a_valid_video_stream(): void
    {
        config(['openbook.video.ffprobe_path' => $this->fakeProbe('{"streams":[],"format":{}}')]);

        $this->expectException(InvalidArgumentException::class);

        app(VideoProbe::class)->inspect(__FILE__);
    }

    private function fakeProbe(string $output): string
    {
        $directory = storage_path('framework/testing/video-probe-'.uniqid());
        mkdir($directory, 0777, true);
        $path = $directory.'/ffprobe';
        file_put_contents($path, "#!/bin/sh\nprintf '%s' ".escapeshellarg($output)."\n");
        chmod($path, 0755);

        return $path;
    }
}
