<?php

namespace Tests\Feature\Admin;

use App\Federation\Inbox\InboxItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class AdminDatabaseMaintenanceTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_admin_can_view_database_maintenance_page(): void
    {
        $admin = $this->createFullAccount('admindb');
        $admin->forceFill(['is_admin' => true, 'is_moderator' => true])->save();

        $this->actingAs($admin)
            ->get(route('admin.database.index'))
            ->assertOk()
            ->assertSee(__('openbook.admin.database.title'));
    }

    public function test_moderators_cannot_view_database_maintenance_page(): void
    {
        $mod = $this->createFullAccount('moddb');
        $mod->forceFill(['is_moderator' => true])->save();

        $this->actingAs($mod)
            ->get(route('admin.database.index'))
            ->assertForbidden();
    }

    public function test_purge_removes_old_inbox_items_but_keeps_recent_and_pending(): void
    {
        $admin = $this->createFullAccount('admindbpurge');
        $admin->forceFill(['is_admin' => true, 'is_moderator' => true])->save();

        $payload = json_encode(['type' => 'Like']);

        InboxItem::query()->create([
            'is_shared' => true,
            'remote_activity_uri' => 'https://remote.test/like/old-processed',
            'activity_type' => 'Like',
            'actor_uri' => 'https://remote.test/users/alice',
            'payload' => $payload,
            'signature_valid' => true,
            'status' => InboxItem::STATUS_PROCESSED,
            'received_at' => now()->subHours(25),
        ]);

        InboxItem::query()->create([
            'is_shared' => true,
            'remote_activity_uri' => 'https://remote.test/like/recent-processed',
            'activity_type' => 'Like',
            'actor_uri' => 'https://remote.test/users/alice',
            'payload' => $payload,
            'signature_valid' => true,
            'status' => InboxItem::STATUS_PROCESSED,
            'received_at' => now()->subHours(2),
        ]);

        InboxItem::query()->create([
            'is_shared' => true,
            'remote_activity_uri' => 'https://remote.test/like/old-pending',
            'activity_type' => 'Like',
            'actor_uri' => 'https://remote.test/users/alice',
            'payload' => $payload,
            'signature_valid' => true,
            'status' => InboxItem::STATUS_PENDING,
            'received_at' => now()->subHours(25),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.database.purge'), ['table' => 'inbox_items'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('inbox_items', [
            'remote_activity_uri' => 'https://remote.test/like/old-processed',
        ]);
        $this->assertDatabaseHas('inbox_items', [
            'remote_activity_uri' => 'https://remote.test/like/recent-processed',
        ]);
        $this->assertDatabaseHas('inbox_items', [
            'remote_activity_uri' => 'https://remote.test/like/old-pending',
        ]);
    }
}
