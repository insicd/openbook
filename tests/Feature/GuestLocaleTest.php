<?php

namespace Tests\Feature;

use App\Http\Middleware\SetUserLocale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

/**
 * Un ospite (non autenticato) vede la homepage nella lingua dedotta
 * dall'header "Accept-Language" del browser: vedi {@see SetUserLocale}.
 */
class GuestLocaleTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_a_guest_with_an_italian_browser_sees_the_italian_homepage(): void
    {
        $response = $this->withHeaders(['Accept-Language' => 'it-IT,it;q=0.9,en;q=0.8'])->get('/');

        $response->assertOk();
        $response->assertSee(__('openbook.home.cta_login', [], 'it'));
    }

    public function test_a_guest_with_a_non_italian_browser_sees_the_english_homepage(): void
    {
        $response = $this->withHeaders(['Accept-Language' => 'fr-FR,fr;q=0.9,de;q=0.8'])->get('/');

        $response->assertOk();
        $response->assertSee(__('openbook.home.cta_login', [], 'en'));
    }

    public function test_a_guest_without_an_accept_language_header_sees_the_instance_default_language(): void
    {
        $response = $this->withHeaders(['Accept-Language' => ''])->get('/');

        $response->assertOk();
        $response->assertSee(__('openbook.home.cta_login', [], config('app.locale')));
    }

    public function test_an_authenticated_user_still_sees_their_own_saved_language_regardless_of_the_browser(): void
    {
        $user = $this->createFullAccount('alice');
        $user->settings()->update(['locale' => 'it']);

        $response = $this->actingAs($user)
            ->withHeaders(['Accept-Language' => 'en-US,en;q=0.9'])
            ->get('/home');

        $response->assertOk();
        $this->assertSame('it', app()->getLocale());
    }
}
