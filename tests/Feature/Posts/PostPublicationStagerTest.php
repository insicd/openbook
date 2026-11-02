<?php

namespace Tests\Feature\Posts;

use App\Application\Services\PendingPostFinalizer;
use App\Application\Services\PostPublicationStager;
use App\Domain\Posts\PendingPostAttachment;
use App\Domain\Posts\PendingPostPublication;
use App\Federation\Serialization\NoteSerializer;
use App\Infrastructure\Database\SystemSetting;
use App\Infrastructure\Media\MediaStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class PostPublicationStagerTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_it_stages_a_compatible_video_without_creating_a_post(): void
    {
        Storage::fake('local');
        config([
            'openbook.video.enabled' => true,
            'openbook.video.ffprobe_path' => $this->fakeProbe(duration: 10, width: 608, height: 1080),
            'openbook.video.passthrough_max_mb' => 8,
        ]);
        $author = $this->createFullAccount('videostager');
        $video = UploadedFile::fake()->createWithContent('clip.mp4', $this->mp4Bytes());

        $publication = app(PostPublicationStager::class)->stage($author->actor, [
            'body' => 'Un video in attesa.',
            'visibility' => 'public',
            'images' => [$video],
            'alt_texts' => ['Una breve clip'],
        ]);

        $this->assertDatabaseCount('posts', 0);
        $this->assertSame('Un video in attesa.', $publication->payload['body']);
        $this->assertCount(1, $publication->attachments);
        $this->assertSame(PendingPostAttachment::PROCESS_COPY, $publication->attachments->first()->processing);
        Storage::disk('local')->assertExists($publication->attachments->first()->path);
    }

    public function test_it_marks_an_incompatible_video_for_transcoding(): void
    {
        Storage::fake('local');
        config([
            'openbook.video.enabled' => true,
            'openbook.video.ffprobe_path' => $this->fakeProbe(duration: 10, width: 721, height: 1280, codec: 'vp9'),
        ]);
        $author = $this->createFullAccount('videotranscode');

        $publication = app(PostPublicationStager::class)->stage($author->actor, [
            'body' => 'Da convertire.',
            'images' => [UploadedFile::fake()->createWithContent('clip.mp4', $this->mp4Bytes())],
        ]);

        $this->assertSame(PendingPostAttachment::PROCESS_TRANSCODE, $publication->attachments->first()->processing);
    }

    public function test_it_cleans_up_the_staging_directory_when_probe_fails(): void
    {
        Storage::fake('local');
        config([
            'openbook.video.enabled' => true,
            'openbook.video.ffprobe_path' => '/missing/ffprobe',
        ]);
        $author = $this->createFullAccount('videofailure');

        try {
            app(PostPublicationStager::class)->stage($author->actor, [
                'body' => 'Video rotto.',
                'images' => [UploadedFile::fake()->createWithContent('clip.mp4', $this->mp4Bytes())],
            ]);
            $this->fail('Lo staging avrebbe dovuto fallire.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('post_publication_queue', 0);
            $this->assertSame([], Storage::disk('local')->allFiles('post-publication'));
        }
    }

    public function test_it_finalizes_a_staged_post_once_and_exposes_its_poster(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        config([
            'openbook.video.enabled' => true,
            'openbook.video.ffprobe_path' => $this->fakeProbe(duration: 10, width: 608, height: 1080),
            'openbook.video.ffmpeg_path' => $this->fakeFfmpeg(),
        ]);
        $author = $this->createFullAccount('videofinalizer');
        $publication = app(PostPublicationStager::class)->stage($author->actor, [
            'body' => 'Finalmente online.',
            'visibility' => 'public',
            'images' => [UploadedFile::fake()->createWithContent('clip.mp4', $this->mp4Bytes())],
            'alt_texts' => ['Video dimostrativo'],
        ]);

        $post = app(PendingPostFinalizer::class)->finalize($publication);
        $samePost = app(PendingPostFinalizer::class)->finalize($publication);
        $post->load('media.thumbnail');

        $this->assertSame($post->id, $samePost->id);
        $this->assertDatabaseCount('posts', 1);
        $this->assertSame('video/mp4', $post->media->first()->mime_type);
        $this->assertNotNull($post->media->first()->thumbnail);
        $this->assertFalse(Storage::disk('local')->directoryExists('post-publication/'.$publication->id));
        Storage::disk('public')->assertExists($post->media->first()->path);
        Storage::disk('public')->assertExists($post->media->first()->thumbnail->path);

        $attachment = NoteSerializer::forPost($post)['attachment'][0];
        $this->assertSame('Document', $attachment['type']);
        $this->assertSame('Image', $attachment['icon']['type']);
        $this->assertSame($post->media->first()->thumbnailUrl(), $attachment['icon']['url']);
    }

    public function test_it_transcodes_an_incompatible_video_before_publication(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        config([
            'openbook.video.enabled' => true,
            'openbook.video.ffprobe_path' => $this->fakeProbe(duration: 10, width: 720, height: 1080, codec: 'vp9'),
            'openbook.video.ffmpeg_path' => $this->fakeFfmpeg(),
        ]);
        $author = $this->createFullAccount('videoconverter');
        $publication = app(PostPublicationStager::class)->stage($author->actor, [
            'body' => 'Convertito prima di uscire.',
            'images' => [UploadedFile::fake()->createWithContent('clip.mp4', $this->mp4Bytes())],
        ]);

        $this->assertSame(PendingPostAttachment::PROCESS_TRANSCODE, $publication->attachments->first()->processing);

        $post = app(PendingPostFinalizer::class)->finalize($publication);

        $this->assertSame('video/mp4', $post->media()->first()->mime_type);
        Storage::disk('public')->assertExists($post->media()->first()->path);
        $this->assertFalse(Storage::disk('local')->directoryExists('post-publication/'.$publication->id));
    }

    public function test_staged_poll_duration_starts_at_actual_publication(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        config([
            'openbook.video.enabled' => true,
            'openbook.video.ffprobe_path' => $this->fakeProbe(duration: 10, width: 608, height: 1080),
            'openbook.video.ffmpeg_path' => $this->fakeFfmpeg(),
        ]);
        $author = $this->createFullAccount('stagedpoll');
        $publication = app(PostPublicationStager::class)->stage($author->actor, [
            'body' => 'Un video e una domanda', 'visibility' => 'public',
            'images' => [UploadedFile::fake()->createWithContent('clip.mp4', $this->mp4Bytes())],
            'poll' => ['options' => ['Sì', 'Forse'], 'multiple' => true, 'duration_seconds' => 300],
        ]);
        $this->assertSame(300, $publication->payload['poll']['duration_seconds']);
        $this->assertDatabaseCount('polls', 0);
        $this->travel(30)->minutes();
        $post = app(PendingPostFinalizer::class)->finalize($publication);
        $again = app(PendingPostFinalizer::class)->finalize($publication);
        $this->assertSame($post->id, $again->id);
        $this->assertTrue($post->poll->ends_at->equalTo($post->published_at->copy()->addMinutes(5)));
        $this->assertFalse($post->poll->isClosed());
        $this->assertDatabaseCount('polls', 1);
        $note = NoteSerializer::forPost($post);
        $this->assertSame('Question', $note['type']);
        $this->assertSame('Document', $note['attachment'][0]['type']);
    }

    public function test_failed_s3_finalization_removes_derived_temporaries_and_preserves_sources_for_retry(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake(MediaStorage::OBJECT_DISK);
        Queue::fake();
        SystemSetting::putBool('object_storage_enabled', true);
        config([
            'openbook.video.enabled' => true,
            'openbook.video.ffprobe_path' => $this->fakeProbe(duration: 10, width: 640, height: 360, codec: 'hevc'),
            'openbook.video.ffmpeg_path' => $this->fakeFfmpeg(),
        ]);
        $owner = $this->createFullAccount('objectretryvideo');
        $publication = app(PostPublicationStager::class)->stage($owner->actor, [
            'body' => 'Retry video', 'visibility' => 'public',
            'images' => [UploadedFile::fake()->createWithContent('clip.mp4', $this->mp4Bytes())],
        ]);
        $source = $publication->attachments()->first()->path;
        PendingPostPublication::updating(function ($publication): void {
            if ($publication->post_id !== null) {
                throw new \RuntimeException('Publication database write failed');
            }
        });
        try {
            app(PendingPostFinalizer::class)->finalize($publication);
            $this->fail('Expected publication failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Publication database write failed', $e->getMessage());
            $this->assertDatabaseCount('posts', 0);
            $this->assertDatabaseCount('media', 0);
            Storage::disk(MediaStorage::OBJECT_DISK)->assertDirectoryEmpty('/');
            $this->assertSame([$source], Storage::disk('local')->allFiles(dirname($source)));
        } finally {
            PendingPostPublication::flushEventListeners();
        }
        $post = app(PendingPostFinalizer::class)->finalize($publication);
        $this->assertSame(MediaStorage::OBJECT_DISK, $post->media()->first()->disk);
        $this->assertFalse(Storage::disk('local')->directoryExists(dirname($source)));
    }

    private function fakeProbe(int $duration, int $width, int $height, string $codec = 'h264'): string
    {
        $output = json_encode([
            'streams' => [
                [
                    'codec_type' => 'video',
                    'codec_name' => $codec,
                    'pix_fmt' => 'yuv420p',
                    'width' => $width,
                    'height' => $height,
                    'avg_frame_rate' => '30/1',
                ],
                ['codec_type' => 'audio', 'codec_name' => 'aac'],
            ],
            'format' => ['format_name' => 'mov,mp4,m4a,3gp,3g2,mj2', 'duration' => (string) $duration],
        ], JSON_THROW_ON_ERROR);
        $directory = storage_path('framework/testing/video-probe-'.uniqid());
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

    private function fakeFfmpeg(): string
    {
        $directory = storage_path('framework/testing/video-ffmpeg-'.uniqid());
        mkdir($directory, 0777, true);
        $path = $directory.'/ffmpeg';
        file_put_contents($path, <<<'SH'
#!/bin/sh
for argument in "$@"; do
    destination="$argument"
done
case "$destination" in
    *.jpg) php -r 'imagejpeg(imagecreatetruecolor(2, 2), $argv[1]);' "$destination" ;;
    *) printf 'generated-media' > "$destination" ;;
esac
SH);
        chmod($path, 0755);

        return $path;
    }
}
