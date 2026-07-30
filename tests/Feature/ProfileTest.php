<?php

namespace Tests\Feature;

use App\Application\Services\AccountRegistrar;
use App\Domain\Accounts\User;
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
}
