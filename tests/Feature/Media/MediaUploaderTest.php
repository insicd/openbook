<?php

namespace Tests\Feature\Media;

use App\Infrastructure\Media\MediaUploader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class MediaUploaderTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_it_stores_a_valid_image_with_a_random_name(): void
    {
        Storage::fake('public');
        $author = $this->createFullAccount('uploader1');

        $file = UploadedFile::fake()->image('originale.jpg', 800, 600);

        $media = app(MediaUploader::class)->store($file, $author->actor);

        $this->assertNotSame('originale.jpg', basename($media->path));
        $this->assertSame('originale.jpg', $media->original_name);
        Storage::disk('public')->assertExists($media->path);
    }

    public function test_it_rejects_a_file_with_a_disallowed_mime_type(): void
    {
        Storage::fake('public');
        $author = $this->createFullAccount('uploader2');

        $file = UploadedFile::fake()->create('script.php', 5, 'application/x-httpd-php');

        $this->expectException(InvalidArgumentException::class);
        app(MediaUploader::class)->store($file, $author->actor);
    }

    public function test_it_rejects_a_file_larger_than_the_configured_limit(): void
    {
        Storage::fake('public');
        config(['openbook.media.max_size_kb' => 100]);
        $author = $this->createFullAccount('uploader3');

        $file = UploadedFile::fake()->create('grande.jpg', 200, 'image/jpeg');

        $this->expectException(InvalidArgumentException::class);
        app(MediaUploader::class)->store($file, $author->actor);
    }

    public function test_it_generates_a_thumbnail_for_large_images(): void
    {
        Storage::fake('public');
        $author = $this->createFullAccount('uploader4');

        $file = UploadedFile::fake()->image('grande.jpg', 1600, 1200);

        $media = app(MediaUploader::class)->store($file, $author->actor);

        $this->assertNotNull($media->thumbnail);
        Storage::disk('public')->assertExists($media->thumbnail->path);
    }

    public function test_it_does_not_generate_a_thumbnail_for_small_images(): void
    {
        Storage::fake('public');
        $author = $this->createFullAccount('uploader5');

        $file = UploadedFile::fake()->image('piccola.jpg', 100, 100);

        $media = app(MediaUploader::class)->store($file, $author->actor);

        $this->assertNull($media->thumbnail);
    }

    public function test_it_makes_the_uploaded_directory_traversable_even_with_a_restrictive_umask(): void
    {
        Storage::fake('public');
        $author = $this->createFullAccount('uploader6');
        $previousUmask = umask(0077);

        try {
            $media = app(MediaUploader::class)->store(UploadedFile::fake()->image('originale.jpg', 800, 600), $author->actor);
        } finally {
            umask($previousUmask);
        }

        $directoryMode = fileperms(dirname(Storage::disk('public')->path($media->path))) & 0777;
        $fileMode = fileperms(Storage::disk('public')->path($media->path)) & 0777;

        $this->assertSame(0755, $directoryMode);
        $this->assertSame(0644, $fileMode);
    }
}
