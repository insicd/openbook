<?php

namespace Tests\Feature\Admin;

use App\Domain\Accounts\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class AdminUserModerationTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_staff_can_suspend_and_unsuspend_a_local_user(): void
    {
        $mod = $this->createFullAccount('moduser');
        $mod->forceFill(['is_moderator' => true])->save();
        $target = $this->createFullAccount('dasospendere');

        $this->actingAs($mod)
            ->post(route('admin.users.suspend', $target))
            ->assertRedirect();

        $this->assertSame(User::STATUS_SUSPENDED, $target->fresh()->status);

        $this->actingAs($mod)
            ->post(route('admin.users.unsuspend', $target))
            ->assertRedirect();

        $this->assertSame(User::STATUS_ACTIVE, $target->fresh()->status);
    }

    public function test_staff_can_disable_a_local_user(): void
    {
        $mod = $this->createFullAccount('moddisable');
        $mod->forceFill(['is_moderator' => true])->save();
        $target = $this->createFullAccount('dadisabilitare');

        $this->actingAs($mod)
            ->post(route('admin.users.disable', $target))
            ->assertRedirect();

        $this->assertSame(User::STATUS_DISABLED, $target->fresh()->status);
    }

    public function test_only_admins_can_promote_and_demote_moderators(): void
    {
        $mod = $this->createFullAccount('modsolo');
        $mod->forceFill(['is_moderator' => true])->save();
        $admin = $this->createFullAccount('adminruoli');
        $admin->forceFill(['is_admin' => true, 'is_moderator' => true])->save();
        $target = $this->createFullAccount('futuromod');

        $this->actingAs($mod)
            ->post(route('admin.users.promote_moderator', $target))
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('admin.users.promote_moderator', $target))
            ->assertRedirect();

        $this->assertTrue($target->fresh()->is_moderator);

        $this->actingAs($admin)
            ->post(route('admin.users.demote_moderator', $target))
            ->assertRedirect();

        $this->assertFalse($target->fresh()->is_moderator);
    }

    public function test_moderators_cannot_suspend_other_moderators(): void
    {
        $modA = $this->createFullAccount('moda');
        $modA->forceFill(['is_moderator' => true])->save();
        $modB = $this->createFullAccount('modb');
        $modB->forceFill(['is_moderator' => true])->save();

        $this->actingAs($modA)
            ->from(route('admin.users.index'))
            ->post(route('admin.users.suspend', $modB))
            ->assertSessionHasErrors('user');

        $this->assertSame(User::STATUS_ACTIVE, $modB->fresh()->status);
    }
}
