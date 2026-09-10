<?php

namespace Tests\Feature\Posts;

use App\Domain\Posts\PendingPostPublication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class VideoUploadFlowTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_video_mimes_are_exposed_only_when_enabled(): void
    {
        $user = $this->createFullAccount('videoaccept');

        config([
            'openbook.media.max_size_kb' => 16384,
            'openbook.video.enabled' => false,
            'openbook.video.max_upload_mb' => 80,
        ]);
        $this->actingAs($user)->get(route('feed.index'))
            ->assertDontSee('application/mp4', false)
            ->assertDontSee('.mov', false)
            ->assertSee('data-max-media-bytes="16777216"', false)
            ->assertSee('data-max-video-bytes="0"', false);

        config(['openbook.video.enabled' => true]);
        $this->actingAs($user)->get(route('feed.index'))
            ->assertSee('application/mp4', false)
            ->assertSee('.mov', false)
            ->assertSee('data-max-video-bytes="83886080"', false);
    }

    public function test_enabled_video_upload_is_staged_and_can_be_deleted(): void
    {
        Storage::fake('local');
        $user = $this->createFullAccount('videoweb');
        config([
            'openbook.video.enabled' => true,
            'openbook.video.ffprobe_path' => $this->fakeProbe(),
        ]);

        $this->actingAs($user)->post(route('posts.store'), [
            'body' => 'Video dal browser.',
            'visibility' => 'public',
            'images' => [UploadedFile::fake()->createWithContent('clip.mp4', $this->mp4Bytes())],
        ])->assertRedirect(route('feed.index'));

        $publication = PendingPostPublication::query()->sole();
        $this->assertDatabaseCount('posts', 0);
        $this->actingAs($user)->get(route('feed.index'))->assertSee('Video dal browser.');

        $this->actingAs($user)->delete(route('posts.pending.destroy', $publication))->assertRedirect();
        $this->assertDatabaseCount('post_publication_queue', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('post-publication'));
    }

    public function test_video_upload_is_rejected_when_disabled(): void
    {
        $user = $this->createFullAccount('videodisabled');
        config(['openbook.video.enabled' => false]);

        $this->actingAs($user)->post(route('posts.store'), [
            'body' => 'Non deve entrare.',
            'visibility' => 'public',
            'images' => [UploadedFile::fake()->createWithContent('clip.mp4', $this->mp4Bytes())],
        ])->assertSessionHasErrors('images.0');

        $this->assertDatabaseCount('post_publication_queue', 0);
    }

    private function fakeProbe(): string
    {
        $output = json_encode([
            'streams' => [[
                'codec_type' => 'video', 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p',
                'width' => 608, 'height' => 1080, 'avg_frame_rate' => '30/1',
            ]],
            'format' => ['format_name' => 'mov,mp4,m4a,3gp,3g2,mj2', 'duration' => '10'],
        ], JSON_THROW_ON_ERROR);
        $directory = storage_path('framework/testing/video-web-'.uniqid());
        mkdir($directory, 0777, true);
        $path = $directory.'/ffprobe';
        file_put_contents($path, "#!/bin/sh\nprintf '%s' ".escapeshellarg($output)."\n");
        chmod($path, 0755);

        return $path;
    }

    private function mp4Bytes(): string
    {
        return pack('N', 24).'ftypisom'.pack('N', 0).'isomiso2';
    }
}
