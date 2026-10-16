<?php

namespace Tests\Feature;

use App\Domain\Accounts\User;
use App\Support\InterfaceLocale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class FinnishLocaleTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_registration_handle_example_uses_the_interface_language(): void
    {
        foreach (['it' => 'nomeutente', 'en' => 'username', 'fi' => 'kayttajatunnus'] as $locale => $example) {
            $this->withCookie(InterfaceLocale::COOKIE_NAME, $locale)
                ->get('/registrati')->assertOk()
                ->assertSee('@'.$example.'@'.config('openbook.domain'));
        }
    }

    public function test_finnish_browser_and_cookie_choose_finnish_without_changing_the_fallback(): void
    {
        $this->assertSame('Suomi', config('openbook.locales.fi'));
        $this->withHeaders(['Accept-Language' => 'fi-FI,fi;q=0.9,en;q=0.8'])
            ->get('/accedi')->assertOk()->assertSee('<html lang="fi">', false)
            ->assertSee('Kirjaudu sisään')->assertSee('Suomi');
        $this->post(route('locale.update'), ['locale' => 'fi', 'return_to' => '/registrati'])
            ->assertRedirect('/registrati')->assertCookie(InterfaceLocale::COOKIE_NAME, 'fi');
        $this->withCookie(InterfaceLocale::COOKIE_NAME, 'fi')
            ->withHeaders(['Accept-Language' => 'en'])->get('/registrati')
            ->assertOk()->assertSee('Luo tilisi')->assertSee('Sähköpostiosoite');
    }

    public function test_new_accounts_inherit_finnish_and_validation_uses_finnish(): void
    {
        Event::fake();
        $this->withCookie(InterfaceLocale::COOKIE_NAME, 'fi');
        $this->from(route('register'))->post(route('register'), [
            'username' => 'SuomiUser', 'email' => 'suomi@example.test',
            'password' => 'weak', 'password_confirmation' => 'weak',
        ])->assertSessionHasErrors('password');
        $this->get(route('register'))->assertOk()->assertSee('Tarkista antamasi tiedot:')
            ->assertSee('Kentän Salasana on sisällettävä vähintään yksi iso ja yksi pieni kirjain.');
        $this->post(route('register'), [
            'username' => 'SuomiUser', 'email' => 'suomi@example.test',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->assertRedirect(route('feed.index'));
        $this->assertSame('fi', User::where('username', 'suomiuser')->firstOrFail()->settings->locale);
    }

    public function test_finnish_account_preference_dates_and_poll_plurals(): void
    {
        $user = $this->createFullAccount('suomilocale');
        $this->actingAs($user)->post(route('locale.update'), ['locale' => 'fi', 'return_to' => '/home'])
            ->assertRedirect('/home');
        $this->actingAs($user->fresh())->withHeaders(['Accept-Language' => 'en'])->get('/home')
            ->assertOk()->assertSee('<html lang="fi">', false)->assertSee('Asetukset');
        $this->assertSame('fi', $user->settings->fresh()->locale);
        $this->assertSame('tammikuu', Carbon::parse('2026-01-15')->translatedFormat('F'));
        $this->assertSame('1 osallistuja', trans_choice('openbook.polls.voters', 1));
        $this->assertSame('2 osallistujaa', trans_choice('openbook.polls.voters', 2));
        $this->assertSame('0 ääntä', trans_choice('openbook.polls.votes', 0));
        $this->assertSame('2 ääntä', trans_choice('openbook.polls.votes', 2));
    }
}
