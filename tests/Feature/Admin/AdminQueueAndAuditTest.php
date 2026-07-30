<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class AdminQueueAndAuditTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_admin_can_view_queue_and_audit_pages(): void
    {
        $admin = $this->createFullAccount('admincoda');
        $admin->forceFill(['is_admin' => true, 'is_moderator' => true])->save();

        $this->actingAs($admin)->get(route('admin.queue.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.audit.index'))->assertOk();
    }

    public function test_moderators_cannot_view_queue_or_audit(): void
    {
        $mod = $this->createFullAccount('modcoda');
        $mod->forceFill(['is_moderator' => true])->save();

        $this->actingAs($mod)->get(route('admin.queue.index'))->assertForbidden();
        $this->actingAs($mod)->get(route('admin.audit.index'))->assertForbidden();
    }
}
