<?php

namespace Tests\Feature\Posts;

use App\Domain\Posts\Post;
use App\Infrastructure\Media\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

/**
 * Le immagini di un post mostrano nel markup la miniatura (piu' leggera) ma
 * portano con se' l'URL dell'originale a piena risoluzione, cosi' il
 * lightbox (public/assets/js/lightbox.js) puo' aprirlo al click senza dover
 * fare un'altra richiesta al server. Vedi {@see Media::thumbnailUrl()}.
 */
class PostImageLightboxTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_a_post_image_links_to_its_full_resolution_original(): void
    {
        Storage::fake('public');
        $user = $this->createFullAccount('fotografo');

        $this->actingAs($user)->post(route('posts.store'), [
            'body' => 'Un post con foto in alta risoluzione.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'images' => [UploadedFile::fake()->image('panorama.jpg', 1600, 1200)],
        ]);

        $media = Media::query()->firstOrFail();
        $this->assertNotSame($media->url(), $media->thumbnailUrl(), 'expected an actual thumbnail to be generated for a large image');

        $post = Post::query()->firstOrFail();
        $response = $this->get(route('posts.show', $post));

        $response->assertOk();
        $response->assertSee('data-lightbox-trigger', false);
        $response->assertSee('src="'.$media->thumbnailUrl().'"', false);
        $response->assertSee('data-full-src="'.$media->url().'"', false);
    }

    public function test_the_lightbox_markup_is_present_on_every_page_including_for_guests(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('id="ob-lightbox"', false);
        $response->assertSee('assets/js/lightbox.js', false);
    }
}
