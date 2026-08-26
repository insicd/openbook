<?php

namespace Tests\Feature\Admin;

use App\Application\Services\InstanceSettings;
use App\Infrastructure\Database\SystemSetting;
use App\Infrastructure\Media\InstanceIconUploader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
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

    public function test_admin_settings_form_includes_favicon_upload(): void
    {
        $admin = $this->createFullAccount('adminfaviconform');
        $admin->forceFill(['is_admin' => true, 'is_moderator' => true])->save();

        $this->actingAs($admin)
            ->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertSee('name="favicon"', false)
            ->assertSee('enctype="multipart/form-data"', false);
    }

    public function test_admin_can_upload_favicon_and_home_screen_icons(): void
    {
        Storage::fake('public');

        $admin = $this->createFullAccount('adminfavicon');
        $admin->forceFill(['is_admin' => true, 'is_moderator' => true])->save();

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), $this->settingsPayload([
                'favicon' => UploadedFile::fake()->image('logo.png', 512, 512),
            ]))
            ->assertRedirect(route('admin.settings.edit'));

        $directory = app(InstanceSettings::class)->iconDirectory();
        $this->assertNotNull($directory);
        Storage::disk('public')->assertExists($directory.'/favicon-32.png');
        Storage::disk('public')->assertExists($directory.'/apple-touch-icon.png');
        Storage::disk('public')->assertExists($directory.'/icon-192.png');
        Storage::disk('public')->assertExists($directory.'/icon-512.png');
        Storage::disk('public')->assertExists($directory.'/icon-192-maskable.png');
        Storage::disk('public')->assertExists($directory.'/icon-512-maskable.png');

        $this->actingAs($admin)
            ->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertSee('rel="apple-touch-icon"', false);

        $this->actingAs($admin)
            ->get(route('feed.index'))
            ->assertOk()
            ->assertSee('rel="apple-touch-icon"', false)
            ->assertSee(route('site.manifest'), false)
            ->assertDontSee(InstanceSettings::DEFAULT_FAVICON_HREF, false);

        $this->get(route('site.manifest'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('display', 'standalone')
            ->assertJsonPath('icons.0.sizes', '192x192')
            ->assertJsonPath('icons.2.sizes', '512x512');
    }

    public function test_admin_can_remove_custom_favicon(): void
    {
        Storage::fake('public');

        $admin = $this->createFullAccount('adminfaviconrm');
        $admin->forceFill(['is_admin' => true, 'is_moderator' => true])->save();

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), $this->settingsPayload([
                'favicon' => UploadedFile::fake()->image('logo.png', 512, 512),
            ]))
            ->assertRedirect(route('admin.settings.edit'));

        $directory = app(InstanceSettings::class)->iconDirectory();
        $this->assertNotNull($directory);

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), $this->settingsPayload([
                'remove_favicon' => '1',
            ]))
            ->assertRedirect(route('admin.settings.edit'));

        $this->assertNull(app(InstanceSettings::class)->iconDirectory());
        Storage::disk('public')->assertMissing($directory.'/favicon-32.png');

        $this->actingAs($admin)
            ->get(route('feed.index'))
            ->assertOk()
            ->assertSee(InstanceSettings::DEFAULT_FAVICON_HREF, false)
            ->assertDontSee('rel="apple-touch-icon"', false);
    }

    public function test_favicon_upload_rejects_invalid_files(): void
    {
        Storage::fake('public');

        $admin = $this->createFullAccount('adminfaviconbad');
        $admin->forceFill(['is_admin' => true, 'is_moderator' => true])->save();

        $this->actingAs($admin)
            ->from(route('admin.settings.edit'))
            ->put(route('admin.settings.update'), $this->settingsPayload([
                'favicon' => UploadedFile::fake()->create('script.php', 5, 'application/x-httpd-php'),
            ]))
            ->assertRedirect(route('admin.settings.edit'))
            ->assertSessionHasErrors('favicon');
    }

    public function test_guest_home_includes_pwa_icon_tags_when_configured(): void
    {
        Storage::fake('public');

        $directory = app(InstanceIconUploader::class)->store(
            UploadedFile::fake()->image('logo.png', 512, 512),
            null,
        );
        SystemSetting::put(InstanceSettings::KEY_INSTANCE_ICON_DIR, $directory);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('rel="apple-touch-icon"', false)
            ->assertSee(route('site.manifest'), false);
    }
}
