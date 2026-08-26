<?php

namespace Tests\Feature\Media;

use App\Infrastructure\Media\InstanceIconUploader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class InstanceIconUploaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_browser_ios_and_android_icons(): void
    {
        Storage::fake('public');

        $directory = app(InstanceIconUploader::class)->store(
            UploadedFile::fake()->image('logo.png', 640, 480),
            null,
        );

        $this->assertTrue(InstanceIconUploader::isValidDirectory($directory));

        $expected = [
            'favicon-32.png' => 32,
            'apple-touch-icon.png' => 180,
            'icon-192.png' => 192,
            'icon-512.png' => 512,
            'icon-192-maskable.png' => 192,
            'icon-512-maskable.png' => 512,
        ];

        foreach ($expected as $filename => $size) {
            $path = $directory.'/'.$filename;
            Storage::disk('public')->assertExists($path);

            [$width, $height] = getimagesize(Storage::disk('public')->path($path));
            $this->assertSame($size, $width, $filename);
            $this->assertSame($size, $height, $filename);
        }
    }

    public function test_it_deletes_the_previous_directory_when_a_new_one_is_stored(): void
    {
        Storage::fake('public');
        $uploader = app(InstanceIconUploader::class);

        $first = $uploader->store(UploadedFile::fake()->image('a.png', 512, 512), null);
        $second = $uploader->store(UploadedFile::fake()->image('b.png', 512, 512), $first);

        Storage::disk('public')->assertMissing($first.'/favicon-32.png');
        Storage::disk('public')->assertExists($second.'/favicon-32.png');
    }

    public function test_it_rejects_an_image_smaller_than_the_minimum(): void
    {
        Storage::fake('public');

        $this->expectException(InvalidArgumentException::class);

        app(InstanceIconUploader::class)->store(
            UploadedFile::fake()->image('tiny.png', 64, 64),
            null,
        );
    }

    public function test_it_rejects_a_disallowed_mime_type(): void
    {
        Storage::fake('public');

        $this->expectException(InvalidArgumentException::class);

        app(InstanceIconUploader::class)->store(
            UploadedFile::fake()->create('script.php', 5, 'application/x-httpd-php'),
            null,
        );
    }
}
