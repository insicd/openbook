<?php

namespace Tests\Feature\Media;

use App\Infrastructure\Media\MediaUploader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class MediaUploaderTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private const CORNER_COLORS = [0xE02020, 0x20E020, 0x2020E0, 0xE0E020];

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

    #[DataProvider('jpegOrientations')]
    public function test_it_applies_exif_orientation_to_the_stored_jpeg_and_thumbnail(int $orientation, array $corners): void
    {
        Storage::fake('public');
        $author = $this->createFullAccount('orientationauthor');
        $file = UploadedFile::fake()->createWithContent('photo.jpg', $this->orientedJpeg($orientation));
        $media = app(MediaUploader::class)->store($file, $author->actor);

        $portrait = $orientation >= 5 && $orientation <= 8;
        $dimensions = $portrait ? [[400, 800], [320, 640]] : [[800, 400], [640, 320]];
        foreach ([$media, $media->thumbnail] as $index => $variant) {
            $this->assertNotNull($variant);
            [$width, $height] = $dimensions[$index];
            $this->assertSame([$width, $height], [$variant->width, $variant->height]);

            $encoded = Storage::disk('public')->get($variant->path);
            $this->assertStringNotContainsString("Exif\0\0", $encoded);
            $image = imagecreatefromstring($encoded);
            $this->assertNotFalse($image);
            $this->assertSame([$width, $height], [imagesx($image), imagesy($image)]);

            foreach ($corners as $i => $corner) {
                $x = $i % 2 === 0 ? intdiv($width, 4) : intdiv(3 * $width, 4);
                $y = $i < 2 ? intdiv($height, 4) : intdiv(3 * $height, 4);
                $actual = imagecolorat($image, $x, $y);
                $expected = self::CORNER_COLORS[$corner];
                foreach ([16, 8, 0] as $shift) {
                    $this->assertEqualsWithDelta(($expected >> $shift) & 0xFF, ($actual >> $shift) & 0xFF, 8);
                }
            }
        }
    }

    public static function jpegOrientations(): array
    {
        return [
            'unchanged' => [1, [0, 1, 2, 3]],
            'horizontal mirror' => [2, [1, 0, 3, 2]],
            'rotate 180' => [3, [3, 2, 1, 0]],
            'vertical mirror' => [4, [2, 3, 0, 1]],
            'transpose' => [5, [0, 2, 1, 3]],
            'rotate clockwise' => [6, [2, 0, 3, 1]],
            'transverse' => [7, [3, 1, 2, 0]],
            'rotate counterclockwise' => [8, [1, 3, 0, 2]],
            'out of range' => [9, [0, 1, 2, 3]],
        ];
    }

    private function orientedJpeg(int $orientation): string
    {
        $image = imagecreatetruecolor(800, 400);
        foreach (self::CORNER_COLORS as $i => $color) {
            $x = $i % 2 * 400;
            $y = intdiv($i, 2) * 200;
            imagefilledrectangle($image, $x, $y, $x + 399, $y + 199, $color);
        }
        ob_start();
        imagejpeg($image);
        $jpeg = ob_get_clean();

        // Exercise both TIFF byte orders and an unrelated APP1 segment before EXIF.
        $tiff = $orientation % 2 === 0
            ? 'II'.pack('vVvvvVv', 42, 8, 1, 0x0112, 3, 1, $orientation)
            : 'MM'.pack('nNnnnNn', 42, 8, 1, 0x0112, 3, 1, $orientation);
        $exif = "Exif\0\0".$tiff."\0\0\0\0\0\0";
        $xmp = "http://ns.adobe.com/xap/1.0/\0<xmp/>";

        return substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($xmp) + 2).$xmp
            ."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
    }

    public function test_it_does_not_generate_a_thumbnail_for_small_images(): void
    {
        Storage::fake('public');
        $author = $this->createFullAccount('uploader5');

        $file = UploadedFile::fake()->image('piccola.jpg', 100, 100);

        $media = app(MediaUploader::class)->store($file, $author->actor);

        $this->assertNull($media->thumbnail);
    }

    public function test_it_preserves_animated_gif_bytes_without_thumbnail(): void
    {
        Storage::fake('public');
        $author = $this->createFullAccount('uploadergif');

        $gifBytes = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        $file = UploadedFile::fake()->createWithContent('anim.gif', $gifBytes, 'image/gif');

        $media = app(MediaUploader::class)->store($file, $author->actor);

        $this->assertSame('image/gif', $media->mime_type);
        $this->assertNull($media->thumbnail);
        $this->assertSame($gifBytes, Storage::disk('public')->get($media->path));
        $this->assertSame($media->url(), $media->displayUrl());
    }

    public function test_it_stores_a_valid_audio_file_with_a_random_name(): void
    {
        Storage::fake('public');
        $author = $this->createFullAccount('audiouploader');

        $file = UploadedFile::fake()->create('clip.mp3', 100, 'audio/mpeg');

        $media = app(MediaUploader::class)->store($file, $author->actor, 'Brano demo');

        $this->assertNotSame('clip.mp3', basename($media->path));
        $this->assertSame('clip.mp3', $media->original_name);
        $this->assertSame('audio/mpeg', $media->mime_type);
        $this->assertTrue($media->isAudio());
        $this->assertSame('Brano demo', $media->alt_text);
        $this->assertNull($media->width);
        Storage::disk('public')->assertExists($media->path);
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
