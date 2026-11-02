<?php

namespace Tests\Feature\Media;

use App\Application\Services\CommentComposer;
use App\Application\Services\EventCommentComposer;
use App\Application\Services\EventCommentSoftDeleter;
use App\Application\Services\EventComposer;
use App\Application\Services\ObjectStorageSettings;
use App\Application\Services\PostComposer;
use App\Domain\Events\Event;
use App\Domain\Events\EventAttachment;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Serialization\NoteSerializer;
use App\Http\Resources\Rest\V1\MediaResource;
use App\Infrastructure\Database\SystemSetting;
use App\Infrastructure\Media\Media;
use App\Infrastructure\Media\MediaStorage;
use App\Infrastructure\Media\MediaUploader;
use App\Infrastructure\Media\MediaVariant;
use Aws\Command;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use League\Flysystem\UnableToReadFile;
use Mockery;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class ObjectStorageImagesTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake(MediaStorage::OBJECT_DISK);
        SystemSetting::putBool('object_storage_enabled', true);
        Queue::fake();
    }

    private function image(int $width = 1200): UploadedFile
    {
        return UploadedFile::fake()->image('photo.jpg', $width, 900);
    }

    private function eventData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Photo event', 'content' => 'Event description',
            'start_at' => now()->addMonth()->format('Y-m-d\TH:i'),
            'timezone' => 'Europe/Rome', 'mode' => 'online',
            'participation_url' => 'https://events.example/live',
            'join_mode' => 'free', 'visibility' => Event::VISIBILITY_PUBLIC,
        ], $overrides);
    }

    public function test_images_and_thumbnails_are_stored_without_a_public_local_copy(): void
    {
        $author = $this->createFullAccount('objectimages');
        $media = app(MediaUploader::class)->store($this->image(), $author->actor, 'Example alt text');
        $this->assertSame(MediaStorage::OBJECT_DISK, $media->disk);
        $this->assertSame(MediaStorage::OBJECT_DISK, $media->thumbnail->disk);
        $this->assertSame('Example alt text', $media->alt_text);
        $this->assertSame(640, $media->thumbnail->width);
        Storage::disk(MediaStorage::OBJECT_DISK)->assertExists([$media->path, $media->thumbnail->path]);
        Storage::disk('public')->assertDirectoryEmpty('/');
    }

    public function test_gif_is_preserved_and_has_no_static_thumbnail(): void
    {
        $author = $this->createFullAccount('objectgif');
        $bytes = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        $media = app(MediaUploader::class)->store(UploadedFile::fake()->createWithContent('anim.gif', $bytes), $author->actor);
        $this->assertSame($bytes, Storage::disk(MediaStorage::OBJECT_DISK)->get($media->path));
        $this->assertNull($media->thumbnail);
        $this->assertSame($media->url(), $media->displayUrl());
    }

    public function test_image_metadata_is_removed_before_remote_storage(): void
    {
        $author = $this->createFullAccount('objectmetadata');
        $file = $this->image();
        $jpeg = file_get_contents($file->getRealPath());
        $metadata = "Exif\0\0Private location marker";
        $jpeg = substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($metadata) + 2).$metadata.substr($jpeg, 2);
        $media = app(MediaUploader::class)->store(UploadedFile::fake()->createWithContent('private.jpg', $jpeg), $author->actor);
        $this->assertStringNotContainsString('Private location marker', Storage::disk(MediaStorage::OBJECT_DISK)->get($media->path));
    }

    public function test_disabling_new_uploads_preserves_existing_object_urls_and_local_files(): void
    {
        $author = $this->createFullAccount('objecttoggle');
        $remoteDiskMedia = app(MediaUploader::class)->store($this->image(), $author->actor);
        $url = $remoteDiskMedia->url();
        SystemSetting::putBool('object_storage_enabled', false);
        $local = app(MediaUploader::class)->store($this->image(), $author->actor);
        $this->assertSame('public', $local->disk);
        Storage::disk('public')->assertExists($local->path);
        $this->assertSame($url, $remoteDiskMedia->url());
        $this->get($url)->assertOk();
    }

    public function test_anonymous_get_head_and_thumbnail_stream_from_storage_without_redirects(): void
    {
        $author = $this->createFullAccount('objectstream');
        $media = app(MediaUploader::class)->store($this->image(), $author->actor);
        $disk = Storage::disk(MediaStorage::OBJECT_DISK);
        $this->get($media->url())->assertOk()->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeaderMissing('Location')
            ->assertStreamedContent($disk->get($media->path));
        $this->get($media->thumbnailUrl())->assertOk()->assertStreamedContent($disk->get($media->thumbnail->path));
        $this->head($media->url())->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertContent('');
    }

    public function test_direct_urls_are_shared_by_rest_federation_and_web_even_when_new_uploads_are_disabled(): void
    {
        $author = $this->createFullAccount('objecturls');
        $post = app(PostComposer::class)->compose($author->actor, ['body' => 'Photo post', 'images' => [$this->image()]]);
        $media = $post->media()->first();
        SystemSetting::putBool('object_storage_direct', true);
        SystemSetting::put('object_storage_public_url', 'https://media.example/bucket/');
        $url = 'https://media.example/bucket/'.$media->path;
        $this->assertSame($url, $media->url());
        $this->assertSame('https://media.example/bucket/'.$media->thumbnail->path, $media->thumbnailUrl());
        $rest = (new MediaResource($media))->resolve();
        $this->assertSame($url, $rest['mediaUrl']);
        $this->assertSame($media->thumbnailUrl(), $rest['mediaPreviewUrl']);
        $this->assertSame($url, NoteSerializer::forPost($post)['attachment'][0]['url']);
        $this->actingAs($author)->get(route('posts.show', $post))->assertOk()->assertSee($media->thumbnailUrl(), false);
        SystemSetting::putBool('object_storage_enabled', false);
        $this->assertSame($url, $media->url());
        SystemSetting::putBool('object_storage_direct', false);
        $this->assertStringContainsString('/media-files/'.$media->id, $media->url());
        $this->get($media->url())->assertOk();
    }

    public function test_new_endpoint_never_serves_remote_local_media_or_arbitrary_paths(): void
    {
        $author = $this->createFullAccount('objectlimits');
        $media = app(MediaUploader::class)->store($this->image(), $author->actor);
        $route = route('media.files.show', $media);
        $this->get($route.'/other')->assertNotFound();
        $this->get($route.'/../../.env')->assertNotFound();
        $media->update(['remote_url' => 'https://remote.example/photo.jpg']);
        $this->assertSame('https://remote.example/photo.jpg', $media->url());
        $this->get($route)->assertNotFound();
        $media->update(['remote_url' => null, 'disk' => 'public']);
        $this->get($route)->assertNotFound();
    }

    public function test_failed_second_image_rolls_back_content_and_cleans_previous_objects(): void
    {
        $author = $this->createFullAccount('objectbatch');
        try {
            app(PostComposer::class)->compose($author->actor, ['body' => 'Failed post', 'images' => [
                $this->image(), UploadedFile::fake()->create('script.php', 1, 'application/x-httpd-php'),
            ]]);
            $this->fail('Expected invalid image');
        } catch (\InvalidArgumentException) {
            $this->assertDatabaseMissing('posts', ['body' => 'Failed post']);
            $this->assertDatabaseCount('media', 0);
            Storage::disk(MediaStorage::OBJECT_DISK)->assertDirectoryEmpty('/');
        }
    }

    public function test_outer_transaction_rollback_cleans_uploaded_files_after_inner_commit(): void
    {
        $author = $this->createFullAccount('objectouter');
        try {
            DB::transaction(function () use ($author): void {
                DB::transaction(fn () => app(MediaUploader::class)->store($this->image(), $author->actor));
                throw new \RuntimeException('Outer transaction failed');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('Outer transaction failed', $e->getMessage());
        }
        $this->assertDatabaseCount('media', 0);
        Storage::disk(MediaStorage::OBJECT_DISK)->assertDirectoryEmpty('/');
    }

    public function test_database_failure_after_thumbnail_write_cleans_original_and_thumbnail(): void
    {
        $author = $this->createFullAccount('objectdatabase');
        MediaVariant::creating(fn () => throw new \RuntimeException('Database failed'));
        try {
            app(MediaUploader::class)->store($this->image(), $author->actor);
            $this->fail('Expected storage error');
        } catch (ValidationException $e) {
            $this->assertSame(__('object_storage.upload_failed'), $e->errors()['images'][0]);
            $this->assertDatabaseCount('media', 0);
            Storage::disk(MediaStorage::OBJECT_DISK)->assertDirectoryEmpty('/');
        } finally {
            MediaVariant::flushEventListeners();
        }
    }

    public function test_failed_storage_write_has_no_local_fallback_or_secret_in_error(): void
    {
        $author = $this->createFullAccount('objectfailure');
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andThrow(new \RuntimeException('SECRET signed-request'));
        $disk->shouldReceive('delete')->once()->andReturnTrue();
        Storage::set(MediaStorage::OBJECT_DISK, $disk);
        try {
            app(MediaUploader::class)->store($this->image(), $author->actor);
            $this->fail('Expected storage error');
        } catch (ValidationException $e) {
            $this->assertStringNotContainsString('SECRET', $e->getMessage());
            $this->assertDatabaseCount('media', 0);
            Storage::disk('public')->assertDirectoryEmpty('/');
        }
    }

    public function test_comments_events_replacements_and_event_comments_use_the_selected_disk(): void
    {
        $owner = $this->createFullAccount('objectflows');
        $post = app(PostComposer::class)->compose($owner->actor, ['body' => 'Parent post']);
        $comment = app(CommentComposer::class)->compose($owner->actor, $post, 'Photo comment', images: [$this->image()]);
        $this->assertSame(MediaStorage::OBJECT_DISK, $comment->media()->first()->disk);
        $composer = app(EventComposer::class);
        $event = $composer->compose($owner->actor, $this->eventData(['cover' => $this->image()]));
        $old = $event->media()->with('thumbnail')->first();
        $event = $composer->update($owner->actor, $event, $this->eventData(['cover' => $this->image()]));
        $this->assertSame(MediaStorage::OBJECT_DISK, $event->media()->first()->disk);
        Storage::disk(MediaStorage::OBJECT_DISK)->assertMissing([$old->path, $old->thumbnail->path]);
        $this->assertDatabaseMissing('media', ['id' => $old->id]);
        $reply = app(EventCommentComposer::class)->compose($owner->actor, $event, 'Event photo', images: [$this->image()]);
        $media = $reply->media()->with('thumbnail')->first();
        $this->assertSame(MediaStorage::OBJECT_DISK, $media->disk);
        app(EventCommentSoftDeleter::class)->delete($reply);
        Storage::disk(MediaStorage::OBJECT_DISK)->assertMissing([$media->path, $media->thumbnail->path]);
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }

    public function test_failed_remote_deletion_retains_the_orphan_record_for_cleanup(): void
    {
        $owner = $this->createFullAccount('objectdeletefailure');
        $event = app(EventComposer::class)->compose($owner->actor, $this->eventData());
        $reply = app(EventCommentComposer::class)->compose($owner->actor, $event, 'Photo', images: [$this->image()]);
        $media = $reply->media()->with('thumbnail')->first();
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('delete')->andReturnFalse();
        Storage::set(MediaStorage::OBJECT_DISK, $disk);
        app(EventCommentSoftDeleter::class)->delete($reply);
        $this->assertSame('deleted', $reply->fresh()->status);
        $this->assertDatabaseHas('media', ['id' => $media->id, 'path' => $media->path]);
        $this->assertDatabaseHas('media_variants', ['media_id' => $media->id]);
    }

    public function test_event_attachment_failure_rolls_back_the_event_and_cleans_uploaded_files_once(): void
    {
        $owner = $this->createFullAccount('eventattachmentfailure');
        $filesystem = Storage::disk(MediaStorage::OBJECT_DISK);
        $disk = Mockery::mock($filesystem);
        $disk->shouldReceive('delete')->once()->andReturnUsing(fn (array $paths): bool => $filesystem->delete($paths));
        Storage::set(MediaStorage::OBJECT_DISK, $disk);
        EventAttachment::creating(fn () => throw new \RuntimeException('Attachment failed'));
        try {
            app(EventComposer::class)->compose($owner->actor, $this->eventData(['cover' => $this->image()]));
            $this->fail('Expected attachment failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Attachment failed', $exception->getMessage());
            $this->assertDatabaseCount('events', 0);
            $this->assertDatabaseCount('media', 0);
            $this->assertDatabaseCount('media_variants', 0);
            $filesystem->assertDirectoryEmpty('/');
        } finally {
            EventAttachment::flushEventListeners();
        }
    }

    public function test_delivery_failure_after_event_save_keeps_the_event_and_its_files(): void
    {
        $owner = $this->createFullAccount('eventdeliveryfailure');
        $remote = Actor::query()->create([
            'type' => Actor::TYPE_PERSON, 'is_local' => false,
            'preferred_username' => 'remote', 'domain' => 'remote.example',
            'uri' => 'https://remote.example/users/remote',
        ]);
        $remote->endpoints()->create(['inbox' => 'https://remote.example/inbox']);
        Follow::query()->create([
            'follower_id' => $remote->id, 'following_id' => $owner->actor->id,
            'status' => Follow::STATUS_ACCEPTED, 'requested_at' => now(), 'accepted_at' => now(),
        ]);
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Delivery failed'));
        try {
            app(EventComposer::class)->compose($owner->actor, $this->eventData(['cover' => $this->image()]));
            $this->fail('Expected delivery failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Delivery failed', $exception->getMessage());
            $event = Event::query()->sole();
            $media = $event->media()->with('thumbnail')->sole();
            Storage::disk(MediaStorage::OBJECT_DISK)->assertExists([$media->path, $media->thumbnail->path]);
        }
    }

    public function test_missing_files_or_storage_configuration_do_not_expose_internal_errors(): void
    {
        $owner = $this->createFullAccount('objectmissing');
        $media = app(MediaUploader::class)->store($this->image(), $owner->actor);
        Storage::disk(MediaStorage::OBJECT_DISK)->delete($media->path);
        $this->get($media->url())->assertNotFound();
        Storage::forgetDisk(MediaStorage::OBJECT_DISK);
        $this->get($media->url())->assertStatus(503);
    }

    public function test_real_s3_adapter_streams_image_bytes_without_buffering_the_download(): void
    {
        $owner = $this->createFullAccount('objectrealstream');
        app(ObjectStorageSettings::class)->save([
            'provider' => 'cloudflareR2', 'account_id' => str_repeat('a', 32),
            'bucket' => 'example-bucket', 'access_key' => 'example-key', 'password' => 'example-secret',
        ]);
        $media = Media::query()->create([
            'actor_id' => $owner->actor->id, 'disk' => MediaStorage::OBJECT_DISK,
            'path' => 'media/example.jpg', 'mime_type' => 'image/jpeg', 'byte_size' => 7,
        ]);
        $disk = Storage::disk(MediaStorage::OBJECT_DISK);
        $handler = new MockHandler;
        $handler->append(function (CommandInterface $command) {
            $this->assertSame('GetObject', $command->getName());
            $this->assertTrue($command['@http']['stream']);

            return new Result(['Body' => Utils::streamFor('example')]);
        });
        $disk->getClient()->getHandlerList()->setHandler($handler);
        $this->get($media->url())->assertOk()->assertStreamedContent('example');
    }

    public function test_bucket_access_errors_are_unavailable_rather_than_missing_files(): void
    {
        $owner = $this->createFullAccount('objectaccessfailure');
        $media = app(MediaUploader::class)->store($this->image(), $owner->actor);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $cause = new AwsException('PRIVATE signed request', new Command('GetObject'), [
            'response' => new Response(403),
        ]);
        $disk->shouldReceive('readStream')->andThrow(UnableToReadFile::fromLocation($media->path, '', $cause));
        $disk->shouldReceive('delete')->andReturnTrue();
        Storage::set(MediaStorage::OBJECT_DISK, $disk);
        $this->get($media->url())->assertStatus(503)->assertDontSee('PRIVATE');
    }

    public function test_audio_uses_the_selected_storage(): void
    {
        $owner = $this->createFullAccount('objectaudio');
        $media = app(MediaUploader::class)->store(UploadedFile::fake()->create('clip.mp3', 1, 'audio/mpeg'), $owner->actor);
        $this->assertSame(MediaStorage::OBJECT_DISK, $media->disk);
        Storage::disk(MediaStorage::OBJECT_DISK)->assertExists($media->path);
    }
}
