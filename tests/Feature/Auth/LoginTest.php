<?php

namespace Tests\Feature\Auth;

use App\Domain\Accounts\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get(route('login'))->assertOk();
    }

    public function test_a_user_can_authenticate_using_username(): void
    {
        $user = User::factory()->create(['username' => 'accessouser']);

        $response = $this->post(route('login'), [
            'login' => 'accessouser',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('feed.index'));
    }

    public function test_a_user_can_authenticate_using_email(): void
    {
        $user = User::factory()->create(['email' => 'email-login@example.test']);

        $response = $this->post(route('login'), [
            'login' => 'email-login@example.test',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('feed.index'));
    }

    public function test_wrong_password_is_rejected(): void
    {
        User::factory()->create(['username' => 'passwordsbagliate']);

        $response = $this->post(route('login'), [
            'login' => 'passwordsbagliate',
            'password' => 'password-sbagliata',
        ]);

        $response->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_suspended_accounts_cannot_authenticate(): void
    {
        User::factory()->create([
            'username' => 'sospeso',
            'status' => User::STATUS_SUSPENDED,
        ]);

        $response = $this->post(route('login'), [
            'login' => 'sospeso',
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_login_is_rate_limited_after_repeated_failures(): void
    {
        RateLimiter::clear('passwordsbagliate2|127.0.0.1');

        User::factory()->create(['username' => 'passwordsbagliate2']);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login'), [
                'login' => 'passwordsbagliate2',
                'password' => 'password-sbagliata',
            ]);
        }

        $response = $this->post(route('login'), [
            'login' => 'passwordsbagliate2',
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('login');
        $this->assertStringContainsString('Troppi tentativi', session('errors')->first('login'));
        $this->assertGuest();
    }

    public function test_a_user_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout'));

        $response->assertRedirect(route('home'));
        $this->assertGuest();
    }
}
