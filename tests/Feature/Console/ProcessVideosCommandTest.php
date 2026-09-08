<?php

namespace Tests\Feature\Console;

use App\Application\Services\PostPublicationStager;
use App\Domain\Posts\PendingPostPublication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class ProcessVideosCommandTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_once_processes_one_pending_post_and_stops(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $tools = $this->fakeTools();
        $this->enableVideo($tools);
        $publication = $this->stage('worker-success');

        $this->artisan('openbook:process-videos', ['--once' => true])
            ->expectsOutputToContain('pubblicato in')
            ->assertSuccessful();

        $publication->refresh();
        $this->assertSame(PendingPostPublication::STATUS_PUBLISHED, $publication->status);
        $this->assertNotNull($publication->post_id);
        $this->assertDatabaseCount('posts', 1);
    }

    public function test_failure_is_retried_then_becomes_final(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $tools = $this->fakeTools(failProcessing: true);
        $this->enableVideo($tools);
        config(['openbook.video.worker_max_attempts' => 2]);
        $publication = $this->stage('worker-failure');

        $this->artisan('openbook:process-videos', ['--once' => true])->assertFailed();
        $this->assertSame(PendingPostPublication::STATUS_PENDING, $publication->fresh()->status);

        $this->artisan('openbook:process-videos', ['--once' => true])->assertFailed();
        $publication->refresh();

        $this->assertSame(PendingPostPublication::STATUS_FAILED, $publication->status);
        $this->assertSame(2, $publication->attempts);
        $this->assertNotNull($publication->last_error);
        $this->assertDatabaseCount('posts', 0);
    }

    public function test_it_recovers_an_expired_claim(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $tools = $this->fakeTools();
        $this->enableVideo($tools);
        config([
            'openbook.video.worker_claim_seconds' => 60,
            'openbook.video.process_timeout_seconds' => 1,
        ]);
        $publication = $this->stage('worker-recovery');
        $publication->forceFill([
            'status' => PendingPostPublication::STATUS_PROCESSING,
            'attempts' => 1,
            'claim_token' => '8ac784f2-a18b-46d7-8ed9-f79f4c548cbc',
            'claimed_at' => now()->subSeconds(61),
        ])->save();

        $this->artisan('openbook:process-videos', ['--once' => true])->assertSuccessful();

        $publication->refresh();
        $this->assertSame(PendingPostPublication::STATUS_PUBLISHED, $publication->status);
        $this->assertSame(2, $publication->attempts);
    }

    public function test_disabled_video_support_does_not_claim_work(): void
    {
        config(['openbook.video.enabled' => false]);

        $this->artisan('openbook:process-videos', ['--once' => true])
            ->expectsOutputToContain('non e\' abilitato')
            ->assertSuccessful();
    }

    private function stage(string $username): PendingPostPublication
    {
        $author = $this->createFullAccount($username);

        return app(PostPublicationStager::class)->stage($author->actor, [
            'body' => 'Post gestito dal worker.',
            'visibility' => 'public',
            'images' => [UploadedFile::fake()->createWithContent('clip.mp4', $this->mp4Bytes())],
        ]);
    }

    private function enableVideo(string $tools): void
    {
        config([
            'openbook.video.enabled' => true,
            'openbook.video.ffmpeg_path' => $tools.'/ffmpeg',
            'openbook.video.ffprobe_path' => $tools.'/ffprobe',
            'openbook.video.process_timeout_seconds' => 5,
        ]);
    }

    private function fakeTools(bool $failProcessing = false): string
    {
        $directory = storage_path('framework/testing/video-worker-'.uniqid());
        mkdir($directory, 0777, true);
        $probe = json_encode([
            'streams' => [[
                'codec_type' => 'video',
                'codec_name' => 'h264',
                'pix_fmt' => 'yuv420p',
                'width' => 608,
                'height' => 1080,
                'avg_frame_rate' => '30/1',
            ]],
            'format' => ['format_name' => 'mov,mp4,m4a,3gp,3g2,mj2', 'duration' => '10'],
        ], JSON_THROW_ON_ERROR);
        $ffprobe = $directory.'/ffprobe';
        file_put_contents($ffprobe, "#!/bin/sh\nif [ \"\$1\" = \"-version\" ]; then printf 'ffprobe version test-1.0\\n'; else printf '%s' ".escapeshellarg($probe)."; fi\n");
        chmod($ffprobe, 0755);

        $ffmpeg = $directory.'/ffmpeg';
        $processing = $failProcessing
            ? "exit 1\n"
            : "for argument in \"\$@\"; do destination=\"\$argument\"; done\nprintf 'generated-media' > \"\$destination\"\n";
        file_put_contents($ffmpeg, "#!/bin/sh\nif [ \"\$1\" = \"-version\" ]; then printf 'ffmpeg version test-1.0\\n'; exit 0; fi\n{$processing}");
        chmod($ffmpeg, 0755);

        return $directory;
    }

    private function mp4Bytes(): string
    {
        return pack('N', 24).'ftypisom'.pack('N', 0).'isomiso2';
    }
}
