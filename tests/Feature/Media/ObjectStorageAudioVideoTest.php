<?php

namespace Tests\Feature\Media;

use App\Application\Services\ObjectStorageSettings;
use App\Infrastructure\Database\SystemSetting;
use App\Infrastructure\Media\Media;
use App\Infrastructure\Media\MediaStorage;
use App\Infrastructure\Media\MediaUploader;
use App\Infrastructure\Media\PreparedMedia;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class ObjectStorageAudioVideoTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        Storage::fake(MediaStorage::OBJECT_DISK);
        SystemSetting::putBool('object_storage_enabled', true);
    }

    private function video(bool $poster = true): array
    {
        $owner = $this->createFullAccount('objectvideo');
        Storage::disk('local')->put('staging/video.mp4', '0123456789');
        if ($poster) {
            $image = UploadedFile::fake()->image('poster.jpg', 320, 180);
            Storage::disk('local')->put('staging/poster.jpg', file_get_contents($image->getRealPath()));
        }
        $prepared = new PreparedMedia('local', 'staging/video.mp4', 'movie.mp4', 'video/mp4', 10, 640, 360, 'Movie', $poster ? 'staging/poster.jpg' : null);

        return [$owner, $prepared];
    }

    public function test_video_and_poster_use_object_storage_and_poster_is_served_as_jpeg(): void
    {
        [$owner, $prepared] = $this->video();
        $media = app(MediaUploader::class)->storePrepared($prepared, $owner->actor);
        $this->assertSame(MediaStorage::OBJECT_DISK, $media->disk);
        $this->assertSame(MediaStorage::OBJECT_DISK, $media->thumbnail->disk);
        $this->assertSame(10, $media->byte_size);
        $this->assertSame(320, $media->thumbnail->width);
        Storage::disk('public')->assertDirectoryEmpty('/');
        $this->get($media->url())->assertOk()->assertHeader('Content-Type', 'video/mp4')->assertHeader('Content-Length', '10')->assertStreamedContent('0123456789');
        $this->get($media->thumbnailUrl())->assertOk()->assertHeader('Content-Type', 'image/jpeg')
            ->assertStreamedContent(Storage::disk(MediaStorage::OBJECT_DISK)->get($media->thumbnail->path));
        Storage::disk('local')->assertExists('staging/video.mp4');
    }

    #[DataProvider('byteRanges')]
    public function test_audio_byte_ranges(?string $range, int $status, string $body, ?string $contentRange): void
    {
        $owner = $this->createFullAccount('objectranges');
        $media = app(MediaUploader::class)->store(UploadedFile::fake()->createWithContent('clip.mp3', '0123456789'), $owner->actor);
        $headers = $range === null ? [] : ['Range' => $range];
        $response = $this->get($media->url(), $headers)->assertStatus($status)->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Content-Length', (string) strlen($body));
        if ($status === 416) {
            $response->assertContent('');
        } else {
            $response->assertStreamedContent($body);
        }
        if ($contentRange === null) {
            $response->assertHeaderMissing('Content-Range');
        } else {
            $response->assertHeader('Content-Range', $contentRange);
        }
    }

    public static function byteRanges(): array
    {
        return [
            [null, 200, '0123456789', null],
            ['bytes=0-0', 206, '0', 'bytes 0-0/10'],
            ['bytes=2-5', 206, '2345', 'bytes 2-5/10'],
            ['bytes=7-', 206, '789', 'bytes 7-9/10'],
            ['bytes=-3', 206, '789', 'bytes 7-9/10'],
            ['bytes=-99', 206, '0123456789', 'bytes 0-9/10'],
            ['bytes=0-99', 206, '0123456789', 'bytes 0-9/10'],
            ['bytes=10-', 416, '', 'bytes */10'],
            ['bytes=-0', 416, '', 'bytes */10'],
            ['bytes=5-2', 416, '', 'bytes */10'],
            ['bytes=999999999999999999999-', 416, '', 'bytes */10'],
            ['bytes=1-2,5-6', 200, '0123456789', null],
            ['items=1-2', 200, '0123456789', null],
            ['bytes=foo', 200, '0123456789', null],
            ['bytes=-', 200, '0123456789', null],
        ];
    }

    public function test_head_ignores_range_and_if_range_without_a_matching_validator_returns_full_content(): void
    {
        [$owner, $prepared] = $this->video(false);
        $media = app(MediaUploader::class)->storePrepared($prepared, $owner->actor);
        $this->head($media->url(), ['Range' => 'bytes=3-5'])->assertOk()->assertHeader('Content-Length', '10')
            ->assertHeaderMissing('Content-Range')->assertContent('');
        $this->get($media->url(), ['Range' => 'bytes=3-5', 'If-Range' => '"unknown"'])->assertOk()
            ->assertHeaderMissing('Content-Range')->assertStreamedContent('0123456789');
        $this->get($media->url(), ['Range' => 'bytes=10-'])->assertStatus(416)->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_s3_get_object_receives_only_requested_range_and_head_does_not_download_body(): void
    {
        $owner = $this->createFullAccount('objectactualrange');
        app(ObjectStorageSettings::class)->save([
            'provider' => 'cloudflareR2', 'account_id' => str_repeat('a', 32),
            'bucket' => 'example-bucket', 'access_key' => 'example-key', 'password' => 'example-secret',
        ]);
        $media = Media::query()->create([
            'actor_id' => $owner->actor->id, 'disk' => MediaStorage::OBJECT_DISK,
            'path' => 'media/movie.mp4', 'mime_type' => 'video/mp4', 'byte_size' => 10,
        ]);
        $disk = Storage::disk(MediaStorage::OBJECT_DISK);
        $handler = new MockHandler;
        $commands = [];
        $head = function (CommandInterface $command) use (&$commands) {
            $commands[] = $command->getName();
            $this->assertSame('HeadObject', $command->getName());

            return new Result(['ContentLength' => 10]);
        };
        $handler->append($head);
        $handler->append(function (CommandInterface $command) use (&$commands) {
            $commands[] = $command->getName();
            $this->assertSame('GetObject', $command->getName());
            $this->assertSame('bytes=3-6', $command['Range']);
            $this->assertTrue($command['@http']['stream']);

            return new Result(['Body' => Utils::streamFor('3456'), 'ContentRange' => 'bytes 3-6/10']);
        });
        $handler->append($head);
        $disk->getClient()->getHandlerList()->setHandler($handler);
        $this->get($media->url(), ['Range' => 'bytes=3-6'])->assertStatus(206)->assertStreamedContent('3456');
        $this->head($media->url())->assertOk()->assertHeader('Content-Length', '10')->assertContent('');
        $this->assertSame(['HeadObject', 'GetObject', 'HeadObject'], $commands);
    }

    public function test_invalid_provider_range_response_is_not_served_as_valid_partial_content(): void
    {
        $owner = $this->createFullAccount('objectbadrange');
        app(ObjectStorageSettings::class)->save([
            'provider' => 'cloudflareR2', 'account_id' => str_repeat('a', 32),
            'bucket' => 'example-bucket', 'access_key' => 'example-key', 'password' => 'example-secret',
        ]);
        $media = Media::query()->create([
            'actor_id' => $owner->actor->id, 'disk' => MediaStorage::OBJECT_DISK,
            'path' => 'media/movie.mp4', 'mime_type' => 'video/mp4', 'byte_size' => 10,
        ]);
        $handler = new MockHandler([new Result(['ContentLength' => 10]), new Result(['Body' => Utils::streamFor('0123456789')])]);
        Storage::disk(MediaStorage::OBJECT_DISK)->getClient()->getHandlerList()->setHandler($handler);
        $this->get($media->url(), ['Range' => 'bytes=3-6'])->assertStatus(503);
    }

    public function test_disabling_uploads_keeps_existing_video_and_poster_urls_working(): void
    {
        [$owner, $prepared] = $this->video();
        $media = app(MediaUploader::class)->storePrepared($prepared, $owner->actor);
        SystemSetting::putBool('object_storage_enabled', false);
        $this->assertSame('public', app(MediaUploader::class)->storePrepared($prepared, $owner->actor)->disk);
        SystemSetting::putBool('object_storage_direct', true);
        SystemSetting::put('object_storage_public_url', 'https://media.example/');
        $this->assertSame('https://media.example/'.$media->path, $media->url());
        $this->assertSame('https://media.example/'.$media->thumbnail->path, $media->thumbnailUrl());
        SystemSetting::putBool('object_storage_direct', false);
        $this->get($media->url(), ['Range' => 'bytes=1-2'])->assertStatus(206)->assertStreamedContent('12');
    }

    public function test_missing_poster_rolls_back_video_and_preserves_source_for_retry(): void
    {
        [$owner, $prepared] = $this->video();
        Storage::disk('local')->delete($prepared->thumbnailPath);
        try {
            app(MediaUploader::class)->storePrepared($prepared, $owner->actor);
            $this->fail('Expected missing poster error');
        } catch (ValidationException) {
            $this->assertDatabaseCount('media', 0);
            Storage::disk(MediaStorage::OBJECT_DISK)->assertDirectoryEmpty('/');
            Storage::disk('local')->assertExists($prepared->path);
        }
    }

    public function test_poster_failure_closes_streams_and_cleans_remote_video(): void
    {
        [$owner, $prepared] = $this->video();
        $disk = Mockery::mock(FilesystemAdapter::class);
        $streams = [];
        $disk->shouldReceive('put')->twice()->andReturnUsing(function ($path, $stream) use (&$streams) {
            $this->assertTrue(is_resource($stream));
            $streams[] = $stream;
            if (count($streams) > 1) {
                throw new \RuntimeException('SECRET signed request');
            }

            return true;
        });
        $disk->shouldReceive('delete')->once()->andReturnTrue();
        Storage::set(MediaStorage::OBJECT_DISK, $disk);
        try {
            app(MediaUploader::class)->storePrepared($prepared, $owner->actor);
            $this->fail('Expected poster write failure');
        } catch (ValidationException $e) {
            $this->assertStringNotContainsString('SECRET', $e->getMessage());
            $this->assertDatabaseCount('media', 0);
            foreach ($streams as $stream) {
                $this->assertFalse(is_resource($stream));
            }
        }
    }

    public function test_large_video_uses_single_streamed_put_instead_of_multipart_session(): void
    {
        [$owner] = $this->video(false);
        $source = Storage::disk('local')->path('staging/large.mp4');
        $handle = fopen($source, 'wb');
        ftruncate($handle, 17 * 1024 * 1024);
        fclose($handle);
        app(ObjectStorageSettings::class)->save([
            'provider' => 'cloudflareR2', 'account_id' => str_repeat('a', 32),
            'enabled' => true, 'bucket' => 'example-bucket', 'access_key' => 'example-key', 'password' => 'example-secret',
        ]);
        $handler = new MockHandler;
        $handler->append(function (CommandInterface $command) {
            $this->assertSame('PutObject', $command->getName());
            $this->assertFalse(isset($command['ACL']));
            $this->assertSame(17 * 1024 * 1024, $command['Body']->getSize());

            return new Result;
        });
        Storage::disk(MediaStorage::OBJECT_DISK)->getClient()->getHandlerList()->setHandler($handler);
        $prepared = new PreparedMedia('local', 'staging/large.mp4', 'large.mp4', 'video/mp4', 17 * 1024 * 1024, 640, 360, null);
        $media = app(MediaUploader::class)->storePrepared($prepared, $owner->actor);
        $this->assertSame(17 * 1024 * 1024, $media->byte_size);
    }

    public function test_interrupted_download_closes_the_stream_and_does_not_append_error_output(): void
    {
        $owner = $this->createFullAccount('objectinterrupted');
        $media = Media::query()->create([
            'actor_id' => $owner->actor->id, 'disk' => MediaStorage::OBJECT_DISK,
            'path' => 'media/interrupted.mp3', 'mime_type' => 'audio/mpeg', 'byte_size' => 10,
        ]);
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, '0123');
        rewind($stream);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('size')->once()->andReturn(10);
        $disk->shouldReceive('readStream')->once()->andReturn($stream);
        Storage::set(MediaStorage::OBJECT_DISK, $disk);
        Log::spy();

        $this->get($media->url())->assertOk()->assertHeader('Content-Length', '10')->assertStreamedContent('0123');
        $this->assertFalse(is_resource($stream));
        Log::shouldHaveReceived('warning')->once()->with('Media stream interrupted.', ['media_id' => $media->id]);
    }

    public function test_empty_object_has_valid_head_and_rejects_byte_ranges(): void
    {
        $owner = $this->createFullAccount('objectempty');
        $media = Media::query()->create([
            'actor_id' => $owner->actor->id, 'disk' => MediaStorage::OBJECT_DISK,
            'path' => 'media/empty.mp3', 'mime_type' => 'audio/mpeg', 'byte_size' => 0,
        ]);
        Storage::disk(MediaStorage::OBJECT_DISK)->put($media->path, '');
        $this->head($media->url())->assertOk()->assertHeader('Content-Length', '0')->assertContent('');
        $this->get($media->url(), ['Range' => 'bytes=0-'])->assertStatus(416)->assertHeader('Content-Range', 'bytes */0')->assertContent('');
    }

    public function test_failed_publication_transaction_cleans_audio_and_video_objects(): void
    {
        [$owner, $prepared] = $this->video();
        try {
            DB::transaction(function () use ($owner, $prepared): void {
                app(MediaUploader::class)->storePrepared($prepared, $owner->actor);
                app(MediaUploader::class)->store(UploadedFile::fake()->createWithContent('clip.mp3', '0123456789'), $owner->actor);
                throw new \RuntimeException('Publication failed');
            });
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('media', 0);
            Storage::disk(MediaStorage::OBJECT_DISK)->assertDirectoryEmpty('/');
        }
    }
}
