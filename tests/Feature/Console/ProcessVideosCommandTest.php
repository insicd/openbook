<?php

namespace Tests\Feature\Console;

use App\Application\Services\PostPublicationStager;
use App\Domain\Posts\PendingPostPublication;
use App\Infrastructure\Database\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class ProcessVideosCommandTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    #[DataProvider('emailVerificationStates')]
    public function test_once_processes_one_pending_post_and_stops(bool $verified): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $tools = $this->fakeTools();
        $this->enableVideo($tools);
        $held = null;
        if (! $verified) {
            $held = $this->stage('worker-held');
            $held->forceFill(['created_at' => now()->subMinute()])->save();
            $held->actor->user->forceFill(['email_verified_at' => null])->save();
        }

        $publication = $this->stage('worker-success');

        $this->artisan('openbook:process-videos', ['--once' => true])
            ->expectsOutputToContain('pubblicato in')
            ->assertSuccessful();

        $publication->refresh();
        $this->assertSame(PendingPostPublication::STATUS_PUBLISHED, $publication->status);
        $this->assertNotNull($publication->post_id);
        if ($held !== null) {
            $this->assertSame(PendingPostPublication::STATUS_PENDING, $held->fresh()->status);
            $this->assertSame(0, $held->fresh()->attempts);
            $held->actor->user->forceFill(['email_verified_at' => now()])->save();
            $this->artisan('openbook:process-videos', ['--once' => true])->assertSuccessful();
            $this->assertSame(PendingPostPublication::STATUS_PUBLISHED, $held->fresh()->status);
            $this->assertSame(1, $held->fresh()->attempts);
            $this->assertDatabaseCount('posts', 2);

            return;
        }

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

    public function test_worker_reloads_object_storage_configuration_between_publications(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->enableVideo($this->fakeTools());
        SystemSetting::putBool('object_storage_enabled', true);
        SystemSetting::put('object_storage_access_key', 'key-a');
        $resolvedKeys = [];
        $root = storage_path('framework/testing/video-worker-storage-'.uniqid());
        Storage::extend('objectStorage', function () use (&$resolvedKeys, $root) {
            $key = SystemSetting::get('object_storage_access_key');
            $resolvedKeys[] = $key;

            return Storage::build(['driver' => 'local', 'root' => $root]);
        });
        $first = $this->stage('worker-storage-first');
        $first->forceFill(['created_at' => now()->subMinute()])->save();
        $second = $this->stage('worker-storage-second');
        $this->artisan('openbook:process-videos', ['--once' => true])->assertSuccessful();
        $firstMedia = $first->fresh()->post->media()->first();
        $this->assertFileExists($root.'/'.$firstMedia->path);

        // Simulate an admin update from another PHP process: invalidate no
        // disks in this worker, only change the persisted configuration.
        SystemSetting::put('object_storage_access_key', 'key-b');
        $this->artisan('openbook:process-videos', ['--once' => true])->assertSuccessful();
        $secondMedia = $second->fresh()->post->media()->first();
        $this->assertFileExists($root.'/'.$secondMedia->path);
        $this->assertSame(['key-a', 'key-b'], $resolvedKeys);
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
        $success = <<<'SH'
for argument in "$@"; do destination="$argument"; done
case "$destination" in
    *.jpg) php -r 'imagejpeg(imagecreatetruecolor(2, 2), $argv[1]);' "$destination" ;;
    *) printf 'generated-media' > "$destination" ;;
esac

SH;
        $processing = $failProcessing ? "exit 1\n" : $success;
        file_put_contents($ffmpeg, "#!/bin/sh\nif [ \"\$1\" = \"-version\" ]; then printf 'ffmpeg version test-1.0\\n'; exit 0; fi\n{$processing}");
        chmod($ffmpeg, 0755);

        return $directory;
    }

    private function mp4Bytes(): string
    {
        return pack('N', 24).'ftypisom'.pack('N', 0).'isomiso2';
    }
}
