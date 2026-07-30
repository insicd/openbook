<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class AdminPromoteAdminTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_admin_can_promote_and_demote_another_admin(): void
    {
        $admin = $this->createFullAccount('adminuno');
        $admin->forceFill(['is_admin' => true, 'is_moderator' => true])->save();
        $target = $this->createFullAccount('adminfuturo');

        $this->actingAs($admin)
            ->post(route('admin.users.promote_admin', $target))
            ->assertRedirect();

        $this->assertTrue($target->fresh()->is_admin);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.promote_admin']);

        $this->actingAs($admin)
            ->post(route('admin.users.demote_admin', $target))
            ->assertRedirect();

        $this->assertFalse($target->fresh()->is_admin);
    }

    public function test_admin_cannot_demote_themselves(): void
    {
        $admin = $this->createFullAccount('selfadmin');
        $admin->forceFill(['is_admin' => true, 'is_moderator' => true])->save();

        $this->actingAs($admin)
            ->from(route('admin.users.index'))
            ->post(route('admin.users.demote_admin', $admin))
            ->assertSessionHasErrors('user');

        $this->assertTrue($admin->fresh()->is_admin);
    }

    public function test_moderators_cannot_promote_admins(): void
    {
        $mod = $this->createFullAccount('modnoadmin');
        $mod->forceFill(['is_moderator' => true])->save();
        $target = $this->createFullAccount('targetnoadmin');

        $this->actingAs($mod)
            ->post(route('admin.users.promote_admin', $target))
            ->assertForbidden();
    }
}
