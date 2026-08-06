<?php

namespace Tests\Feature\Console;

use App\Console\Commands\PurgeDatabaseCommand;
use App\Federation\Inbox\InboxItem;
use App\Infrastructure\Database\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurgeDatabaseCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_skips_when_last_run_within_retention_window(): void
    {
        SystemSetting::put(PurgeDatabaseCommand::LAST_RUN_SETTING_KEY, now()->toIso8601String());

        InboxItem::query()->create([
            'is_shared' => true,
            'remote_activity_uri' => 'https://remote.test/like/old',
            'activity_type' => 'Like',
            'actor_uri' => 'https://remote.test/users/alice',
            'payload' => json_encode(['type' => 'Like']),
            'signature_valid' => true,
            'status' => InboxItem::STATUS_PROCESSED,
            'received_at' => now()->subHours(25),
        ]);

        $this->artisan('openbook:purge-database')
            ->expectsOutputToContain('saltata')
            ->assertSuccessful();

        $this->assertDatabaseHas('inbox_items', [
            'remote_activity_uri' => 'https://remote.test/like/old',
        ]);
    }

    public function test_purges_when_interval_elapsed(): void
    {
        SystemSetting::put(
            PurgeDatabaseCommand::LAST_RUN_SETTING_KEY,
            now()->subHours(25)->toIso8601String(),
        );

        InboxItem::query()->create([
            'is_shared' => true,
            'remote_activity_uri' => 'https://remote.test/like/old',
            'activity_type' => 'Like',
            'actor_uri' => 'https://remote.test/users/alice',
            'payload' => json_encode(['type' => 'Like']),
            'signature_valid' => true,
            'status' => InboxItem::STATUS_PROCESSED,
            'received_at' => now()->subHours(25),
        ]);

        $this->artisan('openbook:purge-database')
            ->expectsOutputToContain('1 righe eliminate')
            ->assertSuccessful();

        $this->assertDatabaseMissing('inbox_items', [
            'remote_activity_uri' => 'https://remote.test/like/old',
        ]);
    }

    public function test_force_option_bypasses_interval(): void
    {
        SystemSetting::put(PurgeDatabaseCommand::LAST_RUN_SETTING_KEY, now()->toIso8601String());

        InboxItem::query()->create([
            'is_shared' => true,
            'remote_activity_uri' => 'https://remote.test/like/old-force',
            'activity_type' => 'Like',
            'actor_uri' => 'https://remote.test/users/alice',
            'payload' => json_encode(['type' => 'Like']),
            'signature_valid' => true,
            'status' => InboxItem::STATUS_PROCESSED,
            'received_at' => now()->subHours(25),
        ]);

        $this->artisan('openbook:purge-database', ['--force' => true])
            ->expectsOutputToContain('1 righe eliminate')
            ->assertSuccessful();

        $this->assertDatabaseMissing('inbox_items', [
            'remote_activity_uri' => 'https://remote.test/like/old-force',
        ]);
    }
}
