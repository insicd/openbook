<?php

namespace Tests\Feature;

use App\Application\Services\AccountRegistrar;
use App\Application\Services\PostComposer;
use App\Domain\Accounts\User;
use App\Domain\Posts\PostAttachment;
use App\Infrastructure\Media\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
