<?php

namespace Tests\Feature;

use App\Application\Services\InstanceSettings;
use App\Domain\Accounts\User;
use App\Infrastructure\Database\SystemSetting;
use App\Support\InterfaceLocale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class LocalePreferenceTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_guest_pages_share_the_language_selector(): void
    {
        foreach (['/', '/accedi', '/registrati', '/privacy', '/regole', '/eventi'] as $path) {
            $this->get($path)->assertOk()
                ->assertSee('class="ob-language-selector"', false)
                ->assertSee('action="'.route('locale.update').'"', false)
                ->assertSee('Italiano')->assertSee('English');
        }
        SystemSetting::putBool(InstanceSettings::KEY_GUEST_HOME_FEED, true);
        $this->get('/')->assertOk()->assertSee('class="ob-language-selector"', false);
    }

    public function test_switch_requires_csrf_protection(): void
    {
        $this->app['env'] = 'local';
        $this->post(route('locale.update'), ['locale' => 'it'])->assertStatus(419);
    }

    public function test_selector_uses_the_configured_languages(): void
    {
        config(['openbook.locales' => ['it' => 'Italiano', 'en' => 'English', 'fr' => 'Français']]);
        $this->get('/accedi')->assertOk()->assertSee('value="fr"', false)->assertSee('Français');
        $this->post(route('locale.update'), ['locale' => 'fr'])->assertCookie(InterfaceLocale::COOKIE_NAME, 'fr');
    }

    public function test_guest_choice_sets_a_secure_persistent_cookie_and_preserves_the_return_path(): void
    {
        $response = $this->post(route('locale.update'), ['locale' => 'it', 'return_to' => '/eventi?view=past']);
        $response->assertRedirect('/eventi?view=past')->assertCookie(InterfaceLocale::COOKIE_NAME, 'it');
        $cookie = $response->getCookie(InterfaceLocale::COOKIE_NAME);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame('/', $cookie->getPath());
        $this->assertEqualsWithDelta(now()->addYear()->timestamp, $cookie->getExpiresTime(), 86400);

        $this->withCookie(InterfaceLocale::COOKIE_NAME, 'it')
            ->withHeaders(['Accept-Language' => 'en'])
            ->get('/accedi')->assertOk()->assertSee('lang="it"', false);
        $this->get('/privacy')->assertOk()->assertSee('lang="it"', false);
    }

    public function test_an_unsupported_cookie_falls_back_to_the_browser(): void
    {
        $this->withCookie(InterfaceLocale::COOKIE_NAME, 'unsupported')
            ->withHeaders(['Accept-Language' => 'it'])
            ->get('/')->assertOk()->assertSee('lang="it"', false);
    }

    public function test_authenticated_switch_updates_only_the_account_language(): void
    {
        $user = $this->createFullAccount('languageswitch');
        $user->settings()->update(['locale' => 'it', 'discoverable' => false, 'direct_message_policy' => 'nobody']);
        $before = $user->settings->fresh()->getAttributes();
        $this->actingAs($user)->post(route('locale.update'), ['locale' => 'en', 'return_to' => '/home'])
            ->assertRedirect('/home');
        $after = $user->settings->fresh()->getAttributes();
        unset($before['locale'], $before['updated_at'], $after['locale'], $after['updated_at']);
        $this->assertSame($before, $after);
        $this->assertSame('en', $user->settings->fresh()->locale);
        $this->withCookie(InterfaceLocale::COOKIE_NAME, 'it')
            ->withHeaders(['Accept-Language' => 'it'])
            ->get('/home')->assertOk()->assertSee('lang="en"', false)
            ->assertSee('class="ob-language-selector"', false);
    }

    #[DataProvider('registrationLanguages')]
    public function test_registration_inherits_the_effective_language(?string $cookie, string $browser, string $expected): void
    {
        Event::fake();
        if ($cookie !== null) {
            $this->withCookie(InterfaceLocale::COOKIE_NAME, $cookie);
        }
        $this->withHeaders(['Accept-Language' => $browser])->post(route('register'), [
            'username' => 'linguanuova', 'email' => 'language@example.test',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
            'locale' => 'unsupported',
        ])->assertRedirect(route('feed.index'));
        $this->assertSame($expected, User::where('username', 'linguanuova')->firstOrFail()->settings->locale);
    }

    public static function registrationLanguages(): array
    {
        return ['cookie before browser' => ['it', 'en', 'it'], 'English browser' => [null, 'en', 'en'], 'Italian browser' => [null, 'it', 'it']];
    }

    #[DataProvider('invalidReturnPaths')]
    public function test_language_switch_rejects_external_or_malformed_return_paths(string $path): void
    {
        $this->post(route('locale.update'), ['locale' => 'en', 'return_to' => $path])
            ->assertSessionHasErrors('return_to')->assertCookieMissing(InterfaceLocale::COOKIE_NAME);
    }

    public static function invalidReturnPaths(): array
    {
        return [
            ['https://other.example/'], ['//other.example/'], ['/\\other.example/'],
            ['/%2fother.example/'], ['/%5cother.example/'], ["/\r\nLocation: https://other.example"],
        ];
    }

    public function test_unsupported_language_does_not_change_account_preferences(): void
    {
        $user = $this->createFullAccount('languageinvalid');
        $before = $user->settings->locale;
        $this->actingAs($user)->post(route('locale.update'), ['locale' => 'fr'])
            ->assertSessionHasErrors('locale')->assertCookieMissing(InterfaceLocale::COOKIE_NAME);
        $this->assertSame($before, $user->settings->fresh()->locale);
    }

    #[DataProvider('interfaceLanguages')]
    public function test_registration_and_login_errors_follow_the_selected_language(string $locale): void
    {
        $this->withCookie(InterfaceLocale::COOKIE_NAME, $locale);
        $this->from(route('register'))->post(route('register'), [
            'username' => 'invalid!', 'email' => 'language@example.test',
            'password' => 'weak', 'password_confirmation' => 'weak',
        ])->assertSessionHasErrors(['username', 'password']);
        $this->get(route('register'))->assertOk()
            ->assertSee(__('openbook.auth.check_data', [], $locale))
            ->assertSee(__('openbook.auth.username_invalid', [], $locale))
            ->assertSee(__('validation.password.mixed', ['attribute' => __('openbook.auth.password', [], $locale)], $locale));

        $this->from(route('login'))->post(route('login'), ['login' => 'missing', 'password' => 'invalid'])
            ->assertSessionHasErrors('login');
        $this->get(route('login'))->assertOk()
            ->assertSee(__('openbook.auth.check_data', [], $locale))
            ->assertSee(__('openbook.auth.credentials_invalid', [], $locale));
    }

    public static function interfaceLanguages(): array
    {
        return [['it'], ['en']];
    }
}
