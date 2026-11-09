<?php

namespace Tests\Feature\Rest;

use App\Domain\Accounts\User;
use App\Domain\Posts\PendingPostPublication;
use App\Domain\Posts\Post;
use App\Infrastructure\Media\Media;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\UsesPassportKeys;
use Tests\TestCase;

class PostAttachmentTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase, UsesPassportKeys;

    private User $author;

    private ?string $toolsDirectory = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        $this->author = $this->createFullAccount('attachmentauthor');
        Passport::actingAs($this->author, ['api'], 'rest');
        $this->withToken('test-token');
        Storage::fake('local');
        Storage::fake('public');
        Http::preventStrayRequests();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        if ($this->toolsDirectory !== null) {
            (new Filesystem)->deleteDirectory($this->toolsDirectory);
        }
        parent::tearDown();
    }

    private function upload(array $overrides = [], array $files = [], array $fields = []): TestResponse
    {
        $post = array_merge(['postContent' => 'Lake walk', 'postVisibility' => 'public'], $overrides);

        return $this->call('POST', '/rest/v1/posts', ['post' => json_encode($post, JSON_THROW_ON_ERROR), ...$fields],
            files: $files, server: ['CONTENT_TYPE' => 'multipart/form-data; boundary=testBoundary', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-token']);
    }

    private function video(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('clip.mp4', pack('N', 24).'ftypisom'.pack('N', 0).'isomiso2');
    }

    private function enableVideo(bool $failProcessing = false): void
    {
        $this->toolsDirectory = storage_path('framework/testing/rest-video-'.uniqid());
        mkdir($this->toolsDirectory, 0777, true);
        $probe = json_encode(['streams' => [['codec_type' => 'video', 'codec_name' => 'h264',
            'pix_fmt' => 'yuv420p', 'width' => 608, 'height' => 1080, 'avg_frame_rate' => '30/1']],
            'format' => ['format_name' => 'mov,mp4,m4a,3gp,3g2,mj2', 'duration' => '10']], JSON_THROW_ON_ERROR);
        file_put_contents($this->toolsDirectory.'/ffprobe', "#!/bin/sh\nif [ \"\$1\" = \"-version\" ]; then printf 'ffprobe version test-1.0\\n'; else printf '%s' ".escapeshellarg($probe)."; fi\n");
        $success = <<<'SH'
for argument in "$@"; do destination="$argument"; done
case "$destination" in
    *.jpg) php -r 'imagejpeg(imagecreatetruecolor(2, 2), $argv[1]);' "$destination" ;;
    *) printf 'generated-media' > "$destination" ;;
esac
SH;
        $processing = $failProcessing ? 'exit 1' : $success;
        file_put_contents($this->toolsDirectory.'/ffmpeg', "#!/bin/sh\nif [ \"\$1\" = \"-version\" ]; then printf 'ffmpeg version test-1.0\\n'; exit 0; fi\n{$processing}\n");
        chmod($this->toolsDirectory.'/ffprobe', 0755);
        chmod($this->toolsDirectory.'/ffmpeg', 0755);
        config(['openbook.video.enabled' => true, 'openbook.video.ffprobe_path' => $this->toolsDirectory.'/ffprobe',
            'openbook.video.ffmpeg_path' => $this->toolsDirectory.'/ffmpeg']);
    }

    private function publication(string $status = 'pending'): PendingPostPublication
    {
        return PendingPostPublication::create(['actor_id' => $this->author->actor->id,
            'payload' => ['body' => 'Private text', 'visibility' => 'followers'], 'status' => $status]);
    }

    private function url(PendingPostPublication $publication): string
    {
        return '/rest/v1/postPublication/'.$publication->id;
    }

    public function test_json_creation_retains_existing_middleware_normalization(): void
    {
        $this->postJson('/rest/v1/posts', ['postContent' => '  Lake walk  ', 'postVisibility' => ' public '])
            ->assertCreated()->assertJsonPath('postContent', 'Lake walk')->assertJsonPath('postVisibility', 'public');
    }

    public function test_multipart_images_preserve_json_order_alt_text_and_visibility(): void
    {
        $response = $this->upload(['postVisibility' => 'followers', 'postAttachments' => [
            ['filePart' => 'second', 'mediaAltText' => 'Second file shown first'],
            ['filePart' => 'first', 'mediaAltText' => null],
        ]], ['first' => UploadedFile::fake()->image('first.jpg'), 'second' => UploadedFile::fake()->image('second.png')])
            ->assertCreated()->assertJsonPath('postVisibility', 'followers')
            ->assertJsonPath('postAttachments.0.mediaAltText', 'Second file shown first')
            ->assertJsonPath('postAttachments.0.mediaMimeType', 'image/png')
            ->assertJsonPath('postAttachments.1.mediaMimeType', 'image/jpeg')
            ->assertJsonPath('postAttachments.1.mediaAltText', null);
        $response->assertHeader('Location', route('rest.v1.posts.show', $response->json('postId')));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->getJson('/rest/v1/posts/'.$response->json('postId'))->assertOk()->assertExactJson($response->json());
        $this->assertDatabaseCount('post_publication_queue', 0);
        Http::assertNothingSent();
    }

    public function test_multipart_supports_media_only_posts_and_text_without_attachments(): void
    {
        $this->upload(['postContent' => '', 'postAttachments' => [['filePart' => 'image', 'mediaAltText' => '']]],
            ['image' => UploadedFile::fake()->image('lake.jpg')])->assertCreated()
            ->assertJsonPath('postContent', '')->assertJsonPath('postAttachments.0.mediaAltText', null);
        $this->upload()->assertCreated()->assertJsonMissingPath('postAttachments');
        $this->upload(['postAttachments' => []])->assertCreated();
        $this->upload(['postContent' => ''])->assertUnprocessable();
        $this->upload(['postContent' => " \n\t", 'postAttachments' => [['filePart' => 'image']]],
            ['image' => UploadedFile::fake()->image('lake.jpg')])->assertUnprocessable();
    }

    public function test_json_objects_and_file_references_are_strictly_validated(): void
    {
        foreach ([null, (object) [], 'image', [null], [[]], [['filePart' => 'missing']],
            [['filePart' => 'image'], ['filePart' => 'image']], [['filePart' => 'image', 'extra' => 1]],
            [['filePart' => 'post']], [['filePart' => 'image.nested']], [['filePart' => 'image[0]']],
            [['filePart' => 'image', 'mediaAltText' => str_repeat('x', 1001)]],
            [['filePart' => 'image', 'mediaAltText' => 5]],
        ] as $attachments) {
            $this->upload(['postAttachments' => $attachments], ['image' => UploadedFile::fake()->image('lake.jpg')])
                ->assertUnprocessable()->assertHeader('Content-Type', 'application/problem+json');
        }
        $this->upload(['postAttachments' => [['filePart' => 'image']]],
            ['image' => [UploadedFile::fake()->image('lake.jpg')]])->assertUnprocessable();
        $this->upload([], ['unreferenced' => UploadedFile::fake()->image('lake.jpg')])->assertUnprocessable();
        $this->upload([], [], ['visibility' => 'public'])->assertUnprocessable();
        $this->upload(['images' => []])->assertUnprocessable();
        $this->post('/rest/v1/posts', ['post' => '{invalid'], ['Content-Type' => 'multipart/form-data'])->assertBadRequest();
        $this->post('/rest/v1/posts', ['post' => '[]'], ['Content-Type' => 'multipart/form-data'])->assertUnprocessable();
        $this->post('/rest/v1/posts', [], ['Content-Type' => 'multipart/form-data'])->assertUnprocessable();
        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('media', 0);
        $this->assertDatabaseCount('post_publication_queue', 0);
        Queue::assertNothingPushed();
    }

    public function test_existing_media_limits_and_actual_file_types_are_enforced_before_publication(): void
    {
        config(['openbook.media.max_attachments_per_post' => 1, 'openbook.media.max_size_kb' => 1]);
        $this->upload(['postAttachments' => [['filePart' => 'one'], ['filePart' => 'two']]],
            ['one' => UploadedFile::fake()->image('a.jpg'), 'two' => UploadedFile::fake()->image('b.jpg')])->assertUnprocessable();
        $this->upload(['postAttachments' => [['filePart' => 'one']]],
            ['one' => UploadedFile::fake()->create('too-big.jpg', 2, 'image/jpeg')])->assertUnprocessable();
        $this->upload(['postAttachments' => [['filePart' => 'one']]],
            ['one' => UploadedFile::fake()->createWithContent('not-an-image.jpg', 'plain text')])->assertUnprocessable();
        $this->upload(['postAttachments' => [['filePart' => 'video']]], ['video' => $this->video()])->assertUnprocessable();
        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('post_publication_queue', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_audio_and_maximum_alt_text_use_the_same_attachment_contract(): void
    {
        $this->upload(['postAttachments' => [['filePart' => 'audio', 'mediaAltText' => str_repeat('x', 1000)]]],
            ['audio' => UploadedFile::fake()->create('recording.mp3', 1, 'audio/mpeg')])->assertCreated()
            ->assertJsonPath('postAttachments.0.mediaMimeType', 'audio/mpeg')
            ->assertJsonPath('postAttachments.0.mediaAltText', str_repeat('x', 1000));
    }

    public function test_invalid_video_is_rejected_without_leaving_a_staged_publication(): void
    {
        $this->enableVideo();
        config(['openbook.video.max_duration_seconds' => 1]);
        $this->upload(['postAttachments' => [['filePart' => 'video']]], ['video' => $this->video()])->assertUnprocessable();
        $this->assertDatabaseCount('post_publication_queue', 0);
        $this->assertDatabaseCount('post_publication_queue_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('post-publication'));
        $this->assertDatabaseCount('posts', 0);
    }

    public function test_terminal_record_cleanup_expires_monitoring_without_deleting_the_post(): void
    {
        $post = Post::create(['actor_id' => $this->author->actor->id, 'body' => 'Published',
            'visibility' => 'public', 'status' => 'published', 'published_at' => now()]);
        $publication = $this->publication('published');
        $publication->forceFill(['post_id' => $post->id, 'updated_at' => now()->subDays(8)])->save();
        $this->getJson($this->url($publication))->assertOk()->assertJsonPath('postId', $post->id);
        $this->artisan('openbook:purge-database', ['--force' => true])->assertSuccessful();
        $this->getJson($this->url($publication))->assertNotFound();
        $this->getJson('/rest/v1/posts/'.$post->id)->assertOk();
    }

    public function test_partial_uploads_are_rejected_before_saving_media(): void
    {
        $file = new UploadedFile(__FILE__, 'lake.jpg', 'image/jpeg', UPLOAD_ERR_PARTIAL, true);
        $this->upload(['postAttachments' => [['filePart' => 'image']]], ['image' => $file])->assertUnprocessable();
        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('media', 0);
    }

    public function test_failed_temporary_file_cleanup_keeps_the_publication_available_for_retrying_cancellation(): void
    {
        $publication = $this->publication();
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('directoryExists')->once()->andReturnTrue();
        $disk->shouldReceive('deleteDirectory')->once()->andReturnFalse();
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        $this->deleteJson($this->url($publication))->assertStatus(500);
        $this->assertDatabaseHas('post_publication_queue', ['id' => $publication->id, 'status' => 'pending']);
    }

    public function test_later_media_storage_failure_rolls_back_the_post_and_cleans_earlier_files(): void
    {
        Event::listen('eloquent.creating: '.Media::class, function (Media $media): void {
            if ($media->original_name === 'fail.jpg') {
                throw new \RuntimeException('Simulated persistence failure');
            }
        });
        $this->upload(['postAttachments' => [['filePart' => 'one'], ['filePart' => 'two']]],
            ['one' => UploadedFile::fake()->image('ok.jpg'), 'two' => UploadedFile::fake()->image('fail.jpg')])->assertUnprocessable();
        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('media', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
        Queue::assertNothingPushed();
    }

    public function test_video_and_mixed_attachments_are_staged_then_published_and_remain_readable(): void
    {
        $this->enableVideo();
        $response = $this->upload(['postContent' => '', 'postVisibility' => 'followers', 'postAttachments' => [
            ['filePart' => 'image', 'mediaAltText' => 'A lake'], ['filePart' => 'video', 'mediaAltText' => 'Waves'],
        ]], ['video' => $this->video(), 'image' => UploadedFile::fake()->image('lake.jpg')])->assertStatus(202);
        $publication = PendingPostPublication::query()->sole();
        $response->assertExactJson(['postPublicationId' => $publication->id, 'postPublicationStatus' => 'pending', 'postId' => null])
            ->assertHeader('Location', route('rest.v1.postPublication.show', $publication->id));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->getJson($this->url($publication))->assertOk()->assertExactJson($response->json());
        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('media', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(['A lake', 'Waves'], $publication->attachments->pluck('alt_text')->all());
        Queue::assertNothingPushed();
        $this->artisan('openbook:process-videos', ['--once' => true])->assertSuccessful();
        $publication->refresh();
        $this->getJson($this->url($publication))->assertOk()->assertExactJson([
            'postPublicationId' => $publication->id, 'postPublicationStatus' => 'published', 'postId' => $publication->post_id,
        ]);
        $this->getJson('/rest/v1/posts/'.$publication->post_id)->assertOk()->assertJsonPath('postContent', '')
            ->assertJsonPath('postVisibility', 'followers')->assertJsonPath('postAttachments.0.mediaAltText', 'A lake')
            ->assertJsonPath('postAttachments.1.mediaAltText', 'Waves');
        $this->assertSame([], Storage::disk('local')->allFiles('post-publication'));
        $this->deleteJson('/rest/v1/posts/'.$publication->post_id)->assertNoContent();
        $this->getJson($this->url($publication))->assertOk()->assertJsonPath('postId', null);
        Http::assertNothingSent();
    }

    public function test_backend_retries_and_failure_are_exposed_only_as_status(): void
    {
        $this->enableVideo(failProcessing: true);
        config(['openbook.video.worker_max_attempts' => 2]);
        $this->upload(['postAttachments' => [['filePart' => 'video']]], ['video' => $this->video()])->assertStatus(202);
        $publication = PendingPostPublication::query()->sole();
        $this->artisan('openbook:process-videos', ['--once' => true])->assertFailed();
        $this->getJson($this->url($publication))->assertOk()->assertJsonPath('postPublicationStatus', 'pending');
        $this->artisan('openbook:process-videos', ['--once' => true])->assertFailed();
        $this->getJson($this->url($publication))->assertOk()->assertExactJson([
            'postPublicationId' => $publication->id, 'postPublicationStatus' => 'failed', 'postId' => null,
        ]);
        $this->assertNotNull($publication->fresh()->last_error);
        $this->assertDatabaseCount('posts', 0);
    }

    public function test_unverified_accounts_cannot_upload_but_can_read_and_cancel_existing_publications(): void
    {
        $publication = $this->publication();
        $this->author->forceFill(['email_verified_at' => null])->save();
        config(['openbook.video.enabled' => false]);
        $this->upload(['postAttachments' => [['filePart' => 'image']]], ['image' => UploadedFile::fake()->image('lake.jpg')])
            ->assertForbidden()->assertJsonPath('code', 'emailVerificationRequired');
        $this->getJson($this->url($publication))->assertOk();
        $this->deleteJson($this->url($publication))->assertNoContent();
        $this->getJson($this->url($publication))->assertNotFound();
        $this->assertDatabaseCount('posts', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_monitoring_and_cancellation_require_ownership_even_for_staff(): void
    {
        $publication = $this->publication();
        $other = $this->createFullAccount('publicationoutsider');
        $other->forceFill(['is_admin' => true])->save();
        Passport::actingAs($other, ['api'], 'rest');
        $this->getJson($this->url($publication))->assertNotFound();
        $this->deleteJson($this->url($publication))->assertNotFound();
        Passport::actingAs($this->author, [], 'rest');
        $this->getJson($this->url($publication))->assertForbidden();
        $this->deleteJson($this->url($publication))->assertForbidden();
        $this->withoutHeader('Authorization')->getJson($this->url($publication))->assertUnauthorized();
        $this->deleteJson($this->url($publication))->assertUnauthorized();
        $this->assertDatabaseHas('post_publication_queue', ['id' => $publication->id]);
    }

    public function test_cancellation_obeys_processing_and_published_states_and_removes_staged_files(): void
    {
        foreach (['pending', 'failed', 'processing', 'published'] as $status) {
            $publication = $this->publication($status);
            $path = 'post-publication/'.$publication->id.'/original.mp4';
            Storage::disk('local')->put($path, 'private original');
            $response = $this->deleteJson($this->url($publication));
            if (in_array($status, ['pending', 'failed'], true)) {
                $response->assertNoContent();
                Storage::disk('local')->assertMissing($path);
                $this->getJson($this->url($publication))->assertNotFound();
            } else {
                $response->assertStatus($status === 'processing' ? 409 : 404);
                Storage::disk('local')->assertExists($path);
                $this->assertDatabaseHas('post_publication_queue', ['id' => $publication->id, 'status' => $status]);
            }
        }
        $this->getJson('/rest/v1/postPublication/invalid')->assertNotFound();
        $this->deleteJson('/rest/v1/postPublication/invalid')->assertNotFound();
    }
}
