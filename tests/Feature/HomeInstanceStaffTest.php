<?php

namespace Tests\Feature;

use App\Application\Services\InstanceSettings;
use App\Domain\Accounts\User;
use App\Infrastructure\Database\SystemSetting;
use App\Infrastructure\Media\HomeBackgroundUploader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class HomeInstanceStaffTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_guest_home_lists_active_admins_and_moderators(): void
    {
        $admin = $this->createFullAccount('alice');
        $admin->forceFill(['is_admin' => true])->save();

        $moderator = $this->createFullAccount('bob');
        $moderator->forceFill(['is_moderator' => true])->save();

        $regular = $this->createFullAccount('carol');

        $suspended = $this->createFullAccount('dave');
        $suspended->forceFill([
            'is_moderator' => true,
            'status' => User::STATUS_SUSPENDED,
        ])->save();

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee(__('openbook.home.staff_title'), false);
        $response->assertSee('@alice', false);
        $response->assertSee(__('openbook.home.staff_role_admin'), false);
        $response->assertSee('@bob', false);
        $response->assertSee(__('openbook.home.staff_role_moderator'), false);
        $response->assertSee(route('profile.show', 'alice'), false);
        $response->assertSee(route('profile.show', 'bob'), false);
        $response->assertDontSee('@carol', false);
        $response->assertDontSee('@dave', false);
        $response->assertSee(__('openbook.home.software_title'), false);
        $response->assertSee(__('openbook.home.hero_subtitle'), false);
    }

    public function test_guest_home_hides_staff_section_when_none_are_active(): void
    {
        $this->createFullAccount('alice');

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertDontSee(__('openbook.home.staff_title'), false);
    }

    public function test_guest_home_hides_staff_section_when_disabled_in_settings(): void
    {
        $admin = $this->createFullAccount('alice');
        $admin->forceFill(['is_admin' => true])->save();

        SystemSetting::putBool(InstanceSettings::KEY_SHOW_HOME_STAFF, false);

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertDontSee(__('openbook.home.staff_title'), false);
        $response->assertDontSee('@alice', false);
    }

    public function test_guest_home_leads_with_instance_identity(): void
    {
        SystemSetting::put(InstanceSettings::KEY_SITE_NAME, 'Piazza Test');
        SystemSetting::put(InstanceSettings::KEY_SITE_DESCRIPTION, 'Una piazza federata di prova.');

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('Piazza Test', false);
        $response->assertSee('Una piazza federata di prova.', false);
        $response->assertSee(__('openbook.home.software_title'), false);
        $response->assertSee(__('openbook.home.hero_subtitle'), false);
        $response->assertSee('ob-guest-home', false);
        $response->assertDontSee('ob-guest-home--has-bg', false);
        $response->assertDontSee(__('openbook.home.hero_title', ['app' => config('app.name')]), false);
        $response->assertDontSee(__('openbook.home.instance_about_title'), false);

        $html = $response->getContent();
        $this->assertNotFalse(strpos($html, 'Una piazza federata di prova.'));
        $this->assertLessThan(
            strpos($html, __('openbook.home.software_title')),
            strpos($html, 'Una piazza federata di prova.'),
        );
    }

    public function test_guest_home_uses_custom_background_when_configured(): void
    {
        Storage::fake('public');

        $directory = app(HomeBackgroundUploader::class)->store(
            UploadedFile::fake()->image('cover.jpg', 1280, 720),
            null,
        );
        SystemSetting::put(InstanceSettings::KEY_HOME_BACKGROUND_DIR, $directory);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('ob-guest-home--has-bg', false)
            ->assertSee(HomeBackgroundUploader::FILENAME, false);
    }
}
