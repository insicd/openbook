<?php

namespace Tests\Feature\Admin;

use App\Domain\Posts\ExternalLinkPreview;
use App\Domain\Posts\PendingPostAttachment;
use App\Domain\Posts\PendingPostPublication;
use App\Federation\Inbox\InboxItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    public function test_purge_removes_only_expired_terminal_publications_and_their_staging_files(): void
    {
        Storage::fake('local');
        config(['openbook.maintenance.publication_queue_retention_days' => 7]);

        $admin = $this->createFullAccount('adminpublicationpurge');
        $admin->forceFill(['is_admin' => true, 'is_moderator' => true])->save();

        $oldPublished = $this->createPublication($admin->actor->id, PendingPostPublication::STATUS_PUBLISHED, 8);
        $oldFailed = $this->createPublication($admin->actor->id, PendingPostPublication::STATUS_FAILED, 8);
        $oldPending = $this->createPublication($admin->actor->id, PendingPostPublication::STATUS_PENDING, 8);
        $recentFailed = $this->createPublication($admin->actor->id, PendingPostPublication::STATUS_FAILED, 2);

        foreach ([$oldPublished, $oldFailed, $oldPending, $recentFailed] as $publication) {
            Storage::disk('local')->put("post-publication/{$publication->id}/source.mov", 'video');
        }

        $this->actingAs($admin)
            ->post(route('admin.database.purge'), ['table' => 'post_publication_queue'])
            ->assertRedirect()
            ->assertSessionHas('status');

        foreach ([$oldPublished, $oldFailed] as $publication) {
            $this->assertDatabaseMissing('post_publication_queue', ['id' => $publication->id]);
            $this->assertDatabaseMissing('post_publication_queue_attachments', ['publication_id' => $publication->id]);
            Storage::disk('local')->assertMissing("post-publication/{$publication->id}");
        }

        foreach ([$oldPending, $recentFailed] as $publication) {
            $this->assertDatabaseHas('post_publication_queue', ['id' => $publication->id]);
            $this->assertDatabaseHas('post_publication_queue_attachments', ['publication_id' => $publication->id]);
            Storage::disk('local')->assertExists("post-publication/{$publication->id}/source.mov");
        }
    }

    public function test_purge_discards_only_very_old_link_previews(): void
    {
        $admin = $this->createFullAccount('adminpreviewpurge');
        $admin->forceFill(['is_admin' => true])->save();

        foreach (['old' => 31, 'recent' => 8] as $name => $ageDays) {
            ExternalLinkPreview::query()->create([
                'url_hash' => hash('sha256', $name),
                'url' => 'https://example.test/'.$name,
                'available' => true,
                'title' => $name,
                'fetched_at' => now()->subDays($ageDays),
            ]);
        }

        $this->actingAs($admin)
            ->post(route('admin.database.purge'), ['table' => 'external_link_previews'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('external_link_previews', ['url' => 'https://example.test/old']);
        $this->assertDatabaseHas('external_link_previews', ['url' => 'https://example.test/recent']);
    }

    private function createPublication(string $actorId, string $status, int $ageDays): PendingPostPublication
    {
        $publication = PendingPostPublication::query()->create([
            'actor_id' => $actorId,
            'payload' => ['body' => 'Pubblicazione temporanea.'],
            'status' => $status,
        ]);

        PendingPostAttachment::query()->create([
            'publication_id' => $publication->id,
            'position' => 0,
            'disk' => 'local',
            'path' => "post-publication/{$publication->id}/source.mov",
            'original_name' => 'source.mov',
            'mime_type' => 'video/quicktime',
            'byte_size' => 5,
            'media_type' => 'video',
            'processing' => PendingPostAttachment::PROCESS_TRANSCODE,
        ]);

        DB::table('post_publication_queue')
            ->where('id', $publication->id)
            ->update(['created_at' => now()->subDays($ageDays), 'updated_at' => now()->subDays($ageDays)]);

        return $publication->fresh();
    }
}
