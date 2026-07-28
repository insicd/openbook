<?php

namespace Tests\Feature\Media;

use App\Infrastructure\Media\ProfileImageUploader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class ProfileImageUploaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_an_avatar_under_the_avatars_directory(): void
    {
        Storage::fake('public');

        $path = app(ProfileImageUploader::class)->storeAvatar(
            UploadedFile::fake()->image('me.jpg', 300, 300),
            null,
        );

        $this->assertStringStartsWith('avatars/', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_it_stores_a_cover_under_the_covers_directory(): void
    {
        Storage::fake('public');

        $path = app(ProfileImageUploader::class)->storeCover(
            UploadedFile::fake()->image('banner.jpg', 2000, 500),
            null,
        );

        $this->assertStringStartsWith('covers/', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_it_deletes_the_previous_file_when_a_new_one_is_stored(): void
    {
        Storage::fake('public');
        $uploader = app(ProfileImageUploader::class);

        $first = $uploader->storeAvatar(UploadedFile::fake()->image('a.jpg', 300, 300), null);
        $second = $uploader->storeAvatar(UploadedFile::fake()->image('b.jpg', 300, 300), $first);

        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_it_rejects_a_disallowed_mime_type(): void
    {
        Storage::fake('public');

        $this->expectException(InvalidArgumentException::class);

        app(ProfileImageUploader::class)->storeAvatar(
            UploadedFile::fake()->create('script.php', 5, 'application/x-httpd-php'),
            null,
        );
    }

    public function test_it_rejects_a_file_larger_than_the_configured_limit(): void
    {
        Storage::fake('public');
        config(['openbook.media.max_size_kb' => 100]);

        $this->expectException(InvalidArgumentException::class);

        app(ProfileImageUploader::class)->storeAvatar(
            UploadedFile::fake()->create('grande.jpg', 200, 'image/jpeg'),
            null,
        );
    }

    public function test_it_makes_the_uploaded_directory_traversable_even_with_a_restrictive_umask(): void
    {
        Storage::fake('public');
        $previousUmask = umask(0077);

        try {
            $path = app(ProfileImageUploader::class)->storeAvatar(
                UploadedFile::fake()->image('me.jpg', 300, 300),
                null,
            );
        } finally {
            umask($previousUmask);
        }

        $directoryMode = fileperms(dirname(Storage::disk('public')->path($path))) & 0777;
        $fileMode = fileperms(Storage::disk('public')->path($path)) & 0777;

        $this->assertSame(0755, $directoryMode);
        $this->assertSame(0644, $fileMode);
    }

    public function test_it_shrinks_an_oversized_avatar(): void
    {
        Storage::fake('public');

        $path = app(ProfileImageUploader::class)->storeAvatar(
            UploadedFile::fake()->image('big.jpg', 2000, 2000),
            null,
        );

        [$width, $height] = getimagesize(Storage::disk('public')->path($path));

        $this->assertLessThanOrEqual(512, $width);
        $this->assertLessThanOrEqual(512, $height);
    }
}
