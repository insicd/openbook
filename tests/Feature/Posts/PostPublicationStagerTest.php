<?php

namespace Tests\Feature\Posts;

use App\Application\Services\PendingPostFinalizer;
use App\Application\Services\PostPublicationStager;
use App\Domain\Posts\PendingPostAttachment;
use App\Federation\Serialization\NoteSerializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
printf 'generated-media' > "$destination"
SH);
        chmod($path, 0755);

        return $path;
    }
}
