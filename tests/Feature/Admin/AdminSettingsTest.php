<?php

namespace Tests\Feature\Admin;

use App\Application\Services\InstanceSettings;
use App\Infrastructure\Database\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function settingsPayload(array $overrides = []): array
    {
        return array_merge([
            'site_name' => 'Openbook Test',
            'registration_open' => '0',
            'show_home_staff' => '1',
            'instance_rules' => "## Regole\n\nSii gentile.",
            'privacy_policy' => "## Privacy\n\nNon vendiamo i tuoi dati.",
            'post_max_length' => 4000,
            'comment_max_length' => 1500,
            'media_max_size_kb' => 4096,
            'media_max_attachments' => 3,
        ], $overrides);
    }

    public function test_admin_can_update_site_name_limits_and_close_registrations(): void
    {
        $admin = $this->createFullAccount('adminsettings');
        $admin->forceFill(['is_admin' => true, 'is_moderator' => true])->save();

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), $this->settingsPayload())
            ->assertRedirect(route('admin.settings.edit'));

        $this->assertSame('Openbook Test', SystemSetting::get(InstanceSettings::KEY_SITE_NAME));
        $this->assertFalse(app(InstanceSettings::class)->registrationOpen());
        $this->assertTrue(app(InstanceSettings::class)->showHomeStaff());
        $this->assertSame(4000, app(InstanceSettings::class)->postMaxLength());
        $this->assertSame(1500, app(InstanceSettings::class)->commentMaxLength());
        $this->assertStringContainsString('Sii gentile', app(InstanceSettings::class)->instanceRules());
        $this->assertStringContainsString('Non vendiamo', app(InstanceSettings::class)->privacyPolicy());
        $this->assertSame('Openbook Test', config('app.name'));
    }

    public function test_admin_settings_save_does_not_modify_env_file(): void
    {
        $envPath = base_path('.env');

        if (! is_file($envPath)) {
            $this->markTestSkipped('File .env assente in questo ambiente di test.');
        }

        $before = file_get_contents($envPath);
        $this->assertNotFalse($before);

        $admin = $this->createFullAccount('adminnoenv');
        $admin->forceFill(['is_admin' => true, 'is_moderator' => true])->save();

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), $this->settingsPayload([
                'site_name' => 'Nome Solo DB',
            ]))
            ->assertRedirect(route('admin.settings.edit'))
            ->assertSessionHas('status');

        $this->assertSame($before, file_get_contents($envPath));
        $this->assertSame('Nome Solo DB', SystemSetting::get(InstanceSettings::KEY_SITE_NAME));
    }

    public function test_admin_can_hide_staff_block_on_guest_home(): void
    {
        $admin = $this->createFullAccount('adminstafftoggle');
        $admin->forceFill(['is_admin' => true, 'is_moderator' => true])->save();

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), $this->settingsPayload([
                'show_home_staff' => '0',
            ]))
            ->assertRedirect(route('admin.settings.edit'));

        $this->assertFalse(app(InstanceSettings::class)->showHomeStaff());
    }

    public function test_closed_registrations_block_register_and_nodeinfo(): void
    {
        SystemSetting::putBool(InstanceSettings::KEY_REGISTRATION_OPEN, false);

        $this->get(route('register'))->assertForbidden();

        Event::fake();

        $this->post(route('register'), [
            'username' => 'nonposso',
            'email' => 'nonposso@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertForbidden();

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['username' => 'nonposso']);

        $this->get('/nodeinfo/2.1')
            ->assertOk()
            ->assertJsonPath('openRegistrations', false);
    }

    public function test_moderators_cannot_update_instance_settings(): void
    {
        $mod = $this->createFullAccount('modsettings');
        $mod->forceFill(['is_moderator' => true])->save();

        $this->actingAs($mod)
            ->put(route('admin.settings.update'), $this->settingsPayload([
                'site_name' => 'Hack',
                'registration_open' => '1',
            ]))
            ->assertForbidden();
    }

    public function test_instance_rules_page_renders_markdown(): void
    {
        SystemSetting::put(InstanceSettings::KEY_INSTANCE_RULES, "## Hello\n\nWorld");

        $this->get(route('instance.rules'))
            ->assertOk()
            ->assertSee('<h2>Hello</h2>', false)
            ->assertSee('World', false);
    }

    public function test_privacy_policy_page_renders_markdown(): void
    {
        SystemSetting::put(InstanceSettings::KEY_PRIVACY_POLICY, "## Privacy\n\nWe care.");

        $this->get(route('instance.privacy'))
            ->assertOk()
            ->assertSee('<h2>Privacy</h2>', false)
            ->assertSee('We care.', false);
    }
}
