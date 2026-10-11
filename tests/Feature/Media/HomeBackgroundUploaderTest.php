<?php

namespace Tests\Feature\Media;

use App\Infrastructure\Media\HomeBackgroundUploader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class HomeBackgroundUploaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_a_jpeg_and_keeps_landscape_ratio(): void
    {
        Storage::fake('public');

        $directory = app(HomeBackgroundUploader::class)->store(
            UploadedFile::fake()->image('cover.jpg', 1280, 720),
            null,
        );

        $this->assertTrue(HomeBackgroundUploader::isValidDirectory($directory));

        $path = $directory.'/'.HomeBackgroundUploader::FILENAME;
        Storage::disk('public')->assertExists($path);

        [$width, $height] = getimagesize(Storage::disk('public')->path($path));
        $this->assertSame(1280, $width);
        $this->assertSame(720, $height);
    }

    public function test_it_scales_down_oversized_images(): void
    {
        Storage::fake('public');

        $directory = app(HomeBackgroundUploader::class)->store(
            UploadedFile::fake()->image('huge.jpg', 3000, 2000),
            null,
        );

        [$width, $height] = getimagesize(
            Storage::disk('public')->path($directory.'/'.HomeBackgroundUploader::FILENAME),
        );

        $this->assertSame(HomeBackgroundUploader::MAX_EDGE, $width);
        $this->assertSame(1280, $height);
    }

    public function test_it_deletes_the_previous_directory_when_a_new_one_is_stored(): void
    {
        Storage::fake('public');
        $uploader = app(HomeBackgroundUploader::class);

        $first = $uploader->store(UploadedFile::fake()->image('a.jpg', 1280, 720), null);
        $second = $uploader->store(UploadedFile::fake()->image('b.jpg', 1280, 720), $first);

        Storage::disk('public')->assertMissing($first.'/'.HomeBackgroundUploader::FILENAME);
        Storage::disk('public')->assertExists($second.'/'.HomeBackgroundUploader::FILENAME);
    }

    public function test_it_rejects_an_image_smaller_than_the_minimum(): void
    {
        Storage::fake('public');

        $this->expectException(InvalidArgumentException::class);

        app(HomeBackgroundUploader::class)->store(
            UploadedFile::fake()->image('tiny.jpg', 320, 180),
            null,
        );
    }

    public function test_it_rejects_a_disallowed_mime_type(): void
    {
        Storage::fake('public');

        $this->expectException(InvalidArgumentException::class);

        app(HomeBackgroundUploader::class)->store(
            UploadedFile::fake()->create('script.php', 5, 'application/x-httpd-php'),
            null,
        );
    }
}
