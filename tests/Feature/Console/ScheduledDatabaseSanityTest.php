<?php

namespace Tests\Feature\Console;

use App\Application\Services\DatabaseSanity;
use App\Console\Commands\PurgeDatabaseCommand;
use App\Infrastructure\Database\SystemSetting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class ScheduledDatabaseSanityTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_scheduled_cleanup_runs_daily_and_manual_cleanup_bypasses_interval(): void
    {
        $this->freezeTime();
        $id = $this->orphan();
        $this->artisan('openbook:database-sanity', ['--scheduled' => true])->assertSuccessful();
        $this->assertDatabaseMissing('likes', ['id' => $id]);
        $firstRun = SystemSetting::get(DatabaseSanity::LAST_RUN_SETTING_KEY);
        $this->assertSame(now()->toIso8601String(), $firstRun);
        $id = $this->orphan();
        $this->travel(23)->hours();
        $this->artisan('openbook:database-sanity', ['--scheduled' => true])->expectsOutputToContain('saltata')->assertSuccessful();
        $this->assertDatabaseHas('likes', ['id' => $id]);
        $this->assertSame($firstRun, SystemSetting::get(DatabaseSanity::LAST_RUN_SETTING_KEY));
        $this->artisan('openbook:database-sanity')->assertSuccessful();
        $this->assertDatabaseMissing('likes', ['id' => $id]);
        $this->assertSame($firstRun, SystemSetting::get(DatabaseSanity::LAST_RUN_SETTING_KEY));
        $id = $this->orphan();
        $this->travel(1)->hours();
        $this->artisan('openbook:database-sanity', ['--scheduled' => true])->assertSuccessful();
        $this->assertDatabaseMissing('likes', ['id' => $id]);
        $this->assertSame(now()->toIso8601String(), SystemSetting::get(DatabaseSanity::LAST_RUN_SETTING_KEY));
    }

    public function test_cron_defers_sanity_when_maintenance_runs_then_runs_it_only_once(): void
    {
        $this->freezeTime();
        $id = $this->orphan();
        $this->artisan('openbook:cron')->assertSuccessful();
        $this->assertDatabaseHas('likes', ['id' => $id]);
        $this->assertNotNull(SystemSetting::get(PurgeDatabaseCommand::LAST_RUN_SETTING_KEY));
        $this->assertNull(SystemSetting::get(DatabaseSanity::LAST_RUN_SETTING_KEY));
        $this->travel(1)->minutes();
        $this->artisan('openbook:cron')->assertSuccessful();
        $this->assertDatabaseMissing('likes', ['id' => $id]);
        $lastRun = SystemSetting::get(DatabaseSanity::LAST_RUN_SETTING_KEY);
        $id = $this->orphan();
        $this->travel(1)->minutes();
        $this->artisan('openbook:cron')->assertSuccessful();
        $this->assertDatabaseHas('likes', ['id' => $id]);
        $this->assertSame($lastRun, SystemSetting::get(DatabaseSanity::LAST_RUN_SETTING_KEY));
    }

    public function test_web_cron_uses_the_same_schedule(): void
    {
        config(['openbook.web_cron.enabled' => true, 'openbook.web_cron.token' => 'sanity-test-token']);
        SystemSetting::put(PurgeDatabaseCommand::LAST_RUN_SETTING_KEY, now()->toIso8601String());
        $id = $this->orphan();
        $this->get('/cron/run?token=sanity-test-token')->assertOk();
        $this->assertDatabaseMissing('likes', ['id' => $id]);
        $this->assertNotNull(SystemSetting::get(DatabaseSanity::LAST_RUN_SETTING_KEY));
    }

    public function test_busy_lock_does_not_record_a_scheduled_run(): void
    {
        $id = $this->orphan();
        $lock = fopen(storage_path('framework/cache/database-sanity.lock'), 'c');
        flock($lock, LOCK_EX);
        try {
            $this->artisan('openbook:database-sanity', ['--scheduled' => true])->assertSuccessful();
        } finally {
            fclose($lock);
        }
        $this->assertDatabaseHas('likes', ['id' => $id]);
        $this->assertNull(SystemSetting::get(DatabaseSanity::LAST_RUN_SETTING_KEY));
        $this->artisan('openbook:database-sanity', ['--scheduled' => true])->assertSuccessful();
        $this->assertDatabaseMissing('likes', ['id' => $id]);
    }

    public function test_dry_run_does_not_change_daily_timestamp(): void
    {
        $id = $this->orphan();
        $this->artisan('openbook:database-sanity', ['--scheduled' => true, '--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseHas('likes', ['id' => $id]);
        $this->assertNull(SystemSetting::get(DatabaseSanity::LAST_RUN_SETTING_KEY));
    }

    public function test_failure_does_not_record_run_and_releases_lock(): void
    {
        $this->orphan();
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'delete ')) {
                throw new RuntimeException('Injected failure');
            }
        });
        try {
            app(DatabaseSanity::class)->run(100, 5, scheduled: true);
            $this->fail('Expected failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected failure', $exception->getMessage());
        }
        $this->assertNull(SystemSetting::get(DatabaseSanity::LAST_RUN_SETTING_KEY));
        $lock = fopen(storage_path('framework/cache/database-sanity.lock'), 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        fclose($lock);
    }

    public function test_partial_scheduled_cleanup_records_run_and_keeps_manual_resume_available(): void
    {
        $this->orphan();
        $this->orphan();
        $delayed = false;
        DB::listen(function (QueryExecuted $query) use (&$delayed): void {
            if (! $delayed && str_starts_with($query->sql, 'delete ')) {
                $delayed = true;
                usleep(1_100_000);
            }
        });
        $this->artisan('openbook:database-sanity', ['--scheduled' => true, '--batch-size' => 1, '--max-time' => 1])
            ->expectsOutputToContain('Limite di tempo')->assertSuccessful();
        $this->assertDatabaseCount('likes', 1);
        $this->assertNotNull(SystemSetting::get(DatabaseSanity::LAST_RUN_SETTING_KEY));
        $this->artisan('openbook:database-sanity')->assertSuccessful();
        $this->assertDatabaseCount('likes', 0);
    }

    private function orphan(): string
    {
        $account = $this->createFullAccount('scheduled'.Str::lower(Str::random(8)));
        $id = (string) Str::uuid();
        DB::table('likes')->insert(['id' => $id, 'actor_id' => $account->actor->id, 'likeable_type' => 'post', 'likeable_id' => (string) Str::uuid()]);

        return $id;
    }
}
