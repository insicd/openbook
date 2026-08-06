<?php

namespace Tests\Feature;

use App\Application\Services\InstanceSettings;
use App\Domain\Accounts\User;
use App\Infrastructure\Database\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
