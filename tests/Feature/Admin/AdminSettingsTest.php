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
            'instance_rules' => "## Regole\n\nSii gentile.",
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
        $this->assertSame(4000, app(InstanceSettings::class)->postMaxLength());
        $this->assertSame(1500, app(InstanceSettings::class)->commentMaxLength());
        $this->assertStringContainsString('Sii gentile', app(InstanceSettings::class)->instanceRules());
        $this->assertSame('Openbook Test', config('app.name'));
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
}
