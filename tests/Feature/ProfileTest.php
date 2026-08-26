<?php

namespace Tests\Feature;

use App\Application\Services\AccountRegistrar;
use App\Application\Services\PostComposer;
use App\Domain\Accounts\User;
use App\Domain\Posts\Post;
use App\Domain\Posts\PostAttachment;
use App\Infrastructure\Media\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private function createFullAccount(string $username): User
    {
        return app(AccountRegistrar::class)->register([
            'username' => $username,
            'email' => $username.'@example.test',
            'password' => Hash::make('Password123'),
        ]);
    }

    public function test_the_canonical_profile_page_is_reachable(): void
    {
        $user = $this->createFullAccount('profilotest');

        $response = $this->get('/@profilotest');

        $response->assertOk();
        $response->assertSee('profilotest');
        $response->assertSee('@profilotest@'.config('openbook.domain'));
    }

    public function test_the_legacy_users_url_redirects_permanently_to_the_canonical_url(): void
    {
        $this->createFullAccount('vecchiourl');

        $response = $this->get('/users/vecchiourl');

        $response->assertRedirect('/@vecchiourl');
        $response->assertStatus(301);
    }

    public function test_unknown_username_returns_not_found(): void
    {
        $this->get('/@nessunoquiesiste')->assertNotFound();
    }

    public function test_username_lookup_is_case_insensitive_on_the_legacy_route(): void
    {
        $this->createFullAccount('maiuscole');

        $this->get('/users/MAIUSCOLE')->assertRedirect('/@maiuscole');
    }

    public function test_hashtags_in_the_bio_are_rendered_as_links(): void
    {
        $user = $this->createFullAccount('biotag');
        $user->profile->update(['bio' => 'Amo #openbook e le #piante']);

        $response = $this->get('/@biotag');

        $response->assertOk();
        $response->assertSee('class="hashtag"', false);
        $response->assertSee(route('hashtags.show', 'openbook'), false);
        $response->assertSee(route('hashtags.show', 'piante'), false);
        $response->assertDontSee('Amo #openbook e le #piante', false);
    }

    public function test_the_photos_tab_lists_media_from_the_users_posts(): void
    {
        $user = $this->createFullAccount('fotografo');
        $post = app(PostComposer::class)->compose($user->actor, [
            'body' => 'Una foto al tramonto',
            'visibility' => 'public',
        ]);

        $media = Media::query()->create([
            'actor_id' => $user->actor->id,
            'disk' => 'remote',
            'path' => 'remote/test-photo',
            'remote_url' => 'https://cdn.example/tramonto.jpg',
            'mime_type' => 'image/jpeg',
            'byte_size' => 0,
            'alt_text' => 'Tramonto',
        ]);

        PostAttachment::query()->create([
            'post_id' => $post->id,
            'media_id' => $media->id,
            'position' => 0,
        ]);

        $this->get(route('profile.show', 'fotografo'))
            ->assertOk()
            ->assertSee(__('openbook.profile.tab_photos'))
            ->assertSee(route('profile.photos', 'fotografo'), false);

        $this->get(route('profile.photos', 'fotografo'))
            ->assertOk()
            ->assertSee('ob-photo-grid', false)
            ->assertSee('https://cdn.example/tramonto.jpg', false)
            ->assertSee('Tramonto')
            ->assertSee(route('posts.show', $post), false);
    }

    public function test_the_photos_tab_is_empty_without_attachments(): void
    {
        $this->createFullAccount('senzfoto');

        $this->get(route('profile.photos', 'senzfoto'))
            ->assertOk()
            ->assertSee(__('openbook.profile.no_photos_yet'));
    }

    public function test_the_photos_tab_exposes_infinite_scroll_markup_when_there_are_more_pages(): void
    {
        config(['openbook.profile.photos_per_page' => 2]);

        $user = $this->createFullAccount('fotopages');
        $this->attachPhoto($user, 'https://cdn.example/oldest.jpg', 'Piu vecchia', now()->subMinutes(3));
        $this->attachPhoto($user, 'https://cdn.example/middle.jpg', 'Di mezzo', now()->subMinutes(2));
        $this->attachPhoto($user, 'https://cdn.example/newest.jpg', 'Piu recente', now()->subMinutes(1));

        $response = $this->get(route('profile.photos', 'fotopages'));

        $response->assertOk();
        $response->assertSee('id="ob-photo-grid"', false);
        $response->assertSee('data-infinite-scroll', false);
        $response->assertSee('data-lightbox-group', false);
        $response->assertSee('data-next-url="'.url('/@fotopages/foto?page=2').'"', false);
        $response->assertSee('<noscript>', false);
        $response->assertSee('ob-pagination', false);
        $response->assertSee(__('openbook.profile.infinite_scroll.next'));
        $response->assertSee('https://cdn.example/newest.jpg', false);
        $response->assertSee('https://cdn.example/middle.jpg', false);
        $response->assertDontSee('https://cdn.example/oldest.jpg', false);
        $response->assertDontSee('Pagination Navigation', false);

        $pageTwo = $this->get(route('profile.photos', 'fotopages').'?page=2');
        $pageTwo->assertOk();
        $pageTwo->assertSee('id="ob-photo-grid"', false);
        $pageTwo->assertSee('data-infinite-scroll', false);
        $pageTwo->assertDontSee('data-next-url', false);
        $pageTwo->assertSee('https://cdn.example/oldest.jpg', false);
        $pageTwo->assertDontSee('https://cdn.example/newest.jpg', false);
    }

    public function test_the_photos_tab_has_no_next_page_url_when_every_photo_fits_on_one_page(): void
    {
        $user = $this->createFullAccount('fotouna');
        $this->attachPhoto($user, 'https://cdn.example/unica.jpg', 'Unica');

        $response = $this->get(route('profile.photos', 'fotouna'));

        $response->assertOk();
        $response->assertSee('data-infinite-scroll', false);
        $response->assertDontSee('data-next-url', false);
        $response->assertDontSee('<noscript>', false);
    }

    private function attachPhoto(User $user, string $url, string $alt, ?Carbon $publishedAt = null): Post
    {
        $post = app(PostComposer::class)->compose($user->actor, [
            'body' => $alt,
            'visibility' => 'public',
        ]);

        if ($publishedAt !== null) {
            $post->forceFill(['published_at' => $publishedAt])->save();
        }

        $media = Media::query()->create([
            'actor_id' => $user->actor->id,
            'disk' => 'remote',
            'path' => 'remote/'.md5($url),
            'remote_url' => $url,
            'mime_type' => 'image/jpeg',
            'byte_size' => 0,
            'alt_text' => $alt,
        ]);

        PostAttachment::query()->create([
            'post_id' => $post->id,
            'media_id' => $media->id,
            'position' => 0,
        ]);

        return $post;
    }
}
