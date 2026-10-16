<?php

namespace Tests\Feature;

use App\Support\UserDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class UserTimezoneTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('web')->get('/timezone-test', fn () => [
            'timezone' => app(UserDate::class)->timezone(),
            'automatic' => app(UserDate::class)->isAutomatic(),
        ]);
    }

    public function test_automatic_follows_the_device_without_changing_the_profile(): void
    {
        $user = $this->createFullAccount('automatic');
        $this->assertNull($user->settings->timezone);
        $this->actingAs($user)->withUnencryptedCookie(UserDate::COOKIE_NAME, 'Europe/Helsinki')
            ->get('/timezone-test')->assertJson(['timezone' => 'Europe/Helsinki', 'automatic' => true]);
        $this->withUnencryptedCookie(UserDate::COOKIE_NAME, 'America/New_York')
            ->get('/timezone-test')->assertJson(['timezone' => 'America/New_York', 'automatic' => true]);
        $this->assertNull($user->settings->fresh()->timezone);
    }

    public function test_guests_and_invalid_device_values_use_the_agreed_fallback(): void
    {
        config(['app.timezone' => 'Europe/Rome']);
        $this->get('/timezone-test')->assertJsonPath('timezone', 'Europe/Rome');
        $this->withUnencryptedCookie(UserDate::COOKIE_NAME, 'Europe/Helsinki')
            ->get('/timezone-test')->assertJsonPath('timezone', 'Europe/Helsinki');
        $this->withUnencryptedCookie(UserDate::COOKIE_NAME, 'not/a-zone')
            ->get('/timezone-test')->assertJsonPath('timezone', 'Europe/Rome');
        $this->withUnencryptedCookie(UserDate::COOKIE_NAME, 'Asia/Calcutta')
            ->get('/timezone-test')->assertJsonPath('timezone', 'Asia/Calcutta');
    }

    public function test_explicit_preference_wins_and_does_not_leak_between_requests(): void
    {
        $originalTimezone = date_default_timezone_get();
        $alice = $this->createFullAccount('timezonealice');
        $bob = $this->createFullAccount('timezonebob');
        $alice->settings->update(['timezone' => 'UTC']);
        $bob->settings->update(['timezone' => 'Asia/Tokyo']);
        $this->actingAs($alice)->withUnencryptedCookie(UserDate::COOKIE_NAME, 'Europe/Helsinki')
            ->get('/timezone-test')->assertJson(['timezone' => 'UTC', 'automatic' => false]);
        $this->actingAs($bob)->get('/timezone-test')
            ->assertJson(['timezone' => 'Asia/Tokyo', 'automatic' => false]);
        $this->assertSame($originalTimezone, date_default_timezone_get());
    }

    public function test_settings_can_choose_a_timezone_and_return_to_automatic_without_federating(): void
    {
        $user = $this->createFullAccount('timezonesettings');
        Queue::fake();
        $data = [
            'locale' => $user->settings->locale, 'default_post_visibility' => 'public',
            'discoverable' => $user->settings->discoverable, 'indexable' => $user->settings->indexable,
            'manually_approves_followers' => $user->settings->manually_approves_followers,
        ];
        $this->actingAs($user)->put(route('settings.account.update'), $data + ['timezone' => 'Europe/Helsinki'])
            ->assertRedirect(route('settings.edit'))->assertSessionHasNoErrors();
        $this->assertSame('Europe/Helsinki', $user->settings->fresh()->timezone);
        $this->actingAs($user->fresh())->get(route('settings.edit'))->assertOk()
            ->assertSee('id="settings-timezone"', false)->assertSee('Europe/Helsinki');
        $this->put(route('settings.account.update'), $data + ['timezone' => ''])
            ->assertRedirect(route('settings.edit'))->assertSessionHasNoErrors();
        $this->assertNull($user->settings->fresh()->timezone);
        Queue::assertNothingPushed();
        $this->put(route('settings.account.update'), $data + ['timezone' => '+02:00'])
            ->assertSessionHasErrors('timezone');
        $this->assertNull($user->settings->fresh()->timezone);
    }

    public function test_migration_converts_old_defaults_and_can_roll_back_without_losing_explicit_values(): void
    {
        $user = $this->createFullAccount('timezonemigration');
        $migration = require database_path('migrations/2026_10_16_000000_make_user_timezone_nullable.php');
        $migration->down();
        $this->assertSame(config('app.timezone'), $user->settings->fresh()->timezone);
        $migration->up();
        $this->assertNull($user->settings->fresh()->timezone);
        DB::table('user_settings')->where('user_id', $user->id)->update(['timezone' => 'Europe/Helsinki']);
        $migration->down();
        $this->assertSame('Europe/Helsinki', $user->settings->fresh()->timezone);
        $migration->up();
    }
}
