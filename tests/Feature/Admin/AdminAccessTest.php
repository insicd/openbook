<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_guests_cannot_access_the_admin_panel(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    public function test_regular_users_cannot_access_the_admin_panel(): void
    {
        $user = $this->createFullAccount('utentenormale');

        $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.reports.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.settings.edit'))->assertForbidden();
    }

    public function test_moderators_can_access_moderation_but_not_settings(): void
    {
        $mod = $this->createFullAccount('modpanel');
        $mod->forceFill(['is_moderator' => true])->save();

        $this->actingAs($mod)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($mod)->get(route('admin.reports.index'))->assertOk();
        $this->actingAs($mod)->get(route('admin.users.index'))->assertOk();
        $this->actingAs($mod)->get(route('admin.settings.edit'))->assertForbidden();
    }

    public function test_admins_can_access_the_full_panel(): void
    {
        $admin = $this->createFullAccount('adminpanel');
        $admin->forceFill(['is_admin' => true, 'is_moderator' => true])->save();

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($admin)->get(route('admin.settings.edit'))->assertOk();
        $this->actingAs($admin)
            ->get(route('feed.index'))
            ->assertOk()
            ->assertSee(__('openbook.nav.admin'), false);
    }

    public function test_regular_users_do_not_see_the_admin_sidebar_link(): void
    {
        $user = $this->createFullAccount('senzaadmin');

        $this->actingAs($user)
            ->get(route('feed.index'))
            ->assertOk()
            ->assertDontSee(__('openbook.nav.admin'), false);
    }
}
