<?php

namespace Tests\Feature\Console;

use App\Application\Queries\DatabaseSanityQuery;
use App\Application\Services\InstanceSettings;
use App\Domain\Comments\Comment;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Infrastructure\Database\SystemSetting;
use Illuminate\Console\Command;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class DatabaseSanityCommandTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_dry_run_reports_limited_samples_without_writes_with_retention_disabled(): void
    {
        $user = $this->createFullAccount('drysanity');
        foreach (['likes' => 'likeable', 'mentions' => 'mentionable', 'notifications' => 'notifiable'] as $table => $morph) {
            for ($i = 0; $i < 3; $i++) {
                $row = ['id' => (string) Str::uuid(), $morph.'_type' => 'post', $morph.'_id' => (string) Str::uuid(), 'actor_id' => $user->actor->id];
                if ($table === 'notifications') {
                    $row += ['recipient_id' => $user->id, 'type' => 'mention'];
                }
                DB::table($table)->insert($row);
            }
        }
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop|truncate)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $this->assertSame(Command::SUCCESS, Artisan::call('openbook:database-sanity', ['--dry-run' => true, '--batch-size' => 2]));
        $output = Artisan::output();
        $this->assertSame([], $writes);
        $this->assertStringContainsString('I conteggi non sono totali globali.', $output);
        foreach (['likes', 'mentions', 'notifications'] as $table) {
            $this->assertStringContainsString($table.' / post: 2 orfani nel campione (massimo 2).', $output);
            $this->assertDatabaseCount($table, 3);
        }
        $this->assertSame(0, $user->fresh()->notifications_revision);
    }

    public function test_defaults_and_empty_samples_are_explicit(): void
    {
        $command = Artisan::all()['openbook:database-sanity'];
        $this->assertSame('100', $command->getDefinition()->getOption('batch-size')->getDefault());
        $this->assertSame('1800', $command->getDefinition()->getOption('max-time')->getDefault());
        Artisan::call('openbook:database-sanity', ['--dry-run' => true]);
        $output = Artisan::output();
        foreach (app(DatabaseSanityQuery::class)->relations() as $table => $relation) {
            foreach (array_keys($relation['parents']) as $type) {
                $this->assertStringContainsString("{$table} / {$type}: 0 orfani nel campione (massimo 100).", $output);
            }
        }
    }

    public function test_unknown_types_are_reported_and_preserved_in_both_modes(): void
    {
        $user = $this->createFullAccount('unknowncli');
        DB::table('likes')->insert(['id' => (string) Str::uuid(), 'actor_id' => $user->actor->id, 'likeable_type' => 'future_type', 'likeable_id' => (string) Str::uuid()]);
        foreach ([['--dry-run' => true], []] as $options) {
            $this->assertSame(Command::SUCCESS, Artisan::call('openbook:database-sanity', $options));
            $this->assertStringContainsString('likes / tipo non supportato "future_type": 1 righe conservate (totale per tipo).', Artisan::output());
            $this->assertDatabaseCount('likes', 1);
        }
    }

    public function test_retention_cascade_leaves_orphans_then_sanity_cleans_them_independently(): void
    {
        $user = $this->createFullAccount('integratedsanity');
        $remote = Actor::query()->create(['type' => 'person', 'is_local' => false, 'preferred_username' => 'remote', 'domain' => 'remote.test', 'uri' => 'https://remote.test/users/remote']);
        $post = Post::query()->create(['actor_id' => $remote->id, 'body' => 'Remote fixture', 'uri' => 'https://remote.test/posts/fixture', 'published_at' => now()]);
        $post->forceFill(['created_at' => now()->subDays(100)])->saveQuietly();
        $comment = Comment::query()->create(['post_id' => $post->id, 'actor_id' => $user->actor->id, 'body' => 'Local fixture']);
        DB::table('likes')->insert(['id' => (string) Str::uuid(), 'actor_id' => $user->actor->id, 'likeable_type' => 'comment', 'likeable_id' => $comment->id]);
        DB::table('mentions')->insert(['id' => (string) Str::uuid(), 'actor_id' => $user->actor->id, 'mentionable_type' => 'post', 'mentionable_id' => $post->id]);
        $notification = (string) Str::uuid();
        DB::table('notifications')->insert(['id' => $notification, 'recipient_id' => $user->id, 'type' => 'comment', 'notifiable_type' => 'comment', 'notifiable_id' => $comment->id]);
        DB::table('push_notifications')->insert(['id' => (string) Str::uuid(), 'notification_id' => $notification]);
        SystemSetting::put(InstanceSettings::KEY_REMOTE_POST_PERTINENT_RETENTION_DAYS, '90');
        $this->artisan('openbook:prune-remote-posts')->assertSuccessful();
        $this->assertDatabaseMissing('posts', ['id' => $post->id]);
        $this->assertDatabaseMissing('comments', ['id' => $comment->id]);
        $this->assertDatabaseCount('likes', 1);
        $this->assertDatabaseCount('mentions', 1);
        $this->assertDatabaseCount('notifications', 1);
        SystemSetting::put(InstanceSettings::KEY_REMOTE_POST_PERTINENT_RETENTION_DAYS, '0');

        $this->artisan('openbook:database-sanity', ['--dry-run' => true])->expectsOutputToContain('likes / comment: 1 orfani')->assertSuccessful();
        $this->assertDatabaseCount('likes', 1);
        $this->artisan('openbook:database-sanity', ['--batch-size' => 1])
            ->expectsOutputToContain('likes / comment: 1 righe eliminate.')
            ->expectsOutputToContain('mentions / post: 1 righe eliminate.')
            ->expectsOutputToContain('notifications / comment: 1 righe eliminate.')->assertSuccessful();
        foreach (['likes', 'mentions', 'notifications', 'push_notifications', 'jobs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(1, $user->fresh()->notifications_revision);
        $this->artisan('openbook:database-sanity')->expectsOutputToContain('notifications / comment: 0 righe eliminate.')->assertSuccessful();
        $this->assertSame(1, $user->fresh()->notifications_revision);
    }

    public function test_time_limit_completes_current_batch_and_next_run_continues(): void
    {
        $user = $this->createFullAccount('timedsanity');
        for ($i = 0; $i < 3; $i++) {
            DB::table('likes')->insert(['id' => (string) Str::uuid(), 'actor_id' => $user->actor->id, 'likeable_type' => 'post', 'likeable_id' => (string) Str::uuid()]);
        }
        $delayed = false;
        DB::listen(function (QueryExecuted $query) use (&$delayed): void {
            if (! $delayed && str_starts_with($query->sql, 'delete ')) {
                $delayed = true;
                usleep(1_100_000);
            }
        });
        $this->artisan('openbook:database-sanity', ['--batch-size' => 2, '--max-time' => 1])
            ->expectsOutputToContain('likes / post: 2 righe eliminate.')
            ->expectsOutputToContain('Limite di tempo raggiunto')->assertSuccessful();
        $this->assertDatabaseCount('likes', 1);
        $this->artisan('openbook:database-sanity')->expectsOutputToContain('likes / post: 1 righe eliminate.')->assertSuccessful();
        $this->assertDatabaseCount('likes', 0);
    }

    public function test_lock_prevents_overlap_and_is_independent_of_retention(): void
    {
        $lock = fopen(storage_path('framework/cache/database-sanity.lock'), 'c');
        flock($lock, LOCK_EX);
        try {
            $this->artisan('openbook:database-sanity')->expectsOutputToContain('già in esecuzione')->assertSuccessful();
            $this->artisan('openbook:database-sanity', ['--dry-run' => true])->assertSuccessful();
        } finally {
            fclose($lock);
        }
        $retentionLock = fopen(storage_path('framework/cache/remote-post-retention.lock'), 'c');
        flock($retentionLock, LOCK_EX);
        try {
            $this->artisan('openbook:database-sanity')->expectsOutputToContain('righe eliminate in questa esecuzione')->assertSuccessful();
        } finally {
            fclose($retentionLock);
        }
        $this->assertLockReleased();
    }

    public function test_lock_is_released_on_failure_and_notification_batch_rolls_back(): void
    {
        $user = $this->createFullAccount('failedcli');
        $id = (string) Str::uuid();
        DB::table('notifications')->insert(['id' => $id, 'recipient_id' => $user->id, 'type' => 'mention', 'notifiable_type' => 'post', 'notifiable_id' => (string) Str::uuid()]);
        $failed = false;
        DB::listen(function (QueryExecuted $query) use (&$failed): void {
            if (! $failed && str_starts_with($query->sql, 'update "users"')) {
                $failed = true;
                throw new RuntimeException('Batch failed');
            }
        });
        try {
            Artisan::call('openbook:database-sanity');
            $this->fail('Expected failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Batch failed', $exception->getMessage());
        }
        $this->assertDatabaseHas('notifications', ['id' => $id]);
        $this->assertSame(0, $user->fresh()->notifications_revision);
        $this->assertLockReleased();
        $this->artisan('openbook:database-sanity')->assertSuccessful();
        $this->assertDatabaseMissing('notifications', ['id' => $id]);
    }

    public static function invalidOptions(): array
    {
        return [['--batch-size', '0'], ['--batch-size', '-1'], ['--batch-size', '1.5'], ['--batch-size', 'foo'], ['--batch-size', '9999999999999999999999999'], ['--max-time', '0'], ['--max-time', '-1'], ['--max-time', '1.5'], ['--max-time', 'foo'], ['--max-time', '9999999999999999999999999']];
    }

    #[DataProvider('invalidOptions')]
    public function test_invalid_options_are_rejected(string $option, string $value): void
    {
        $this->artisan('openbook:database-sanity', [$option => $value])->expectsOutputToContain('devono essere interi positivi')->assertExitCode(Command::INVALID);
    }

    private function assertLockReleased(): void
    {
        $lock = fopen(storage_path('framework/cache/database-sanity.lock'), 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        fclose($lock);
    }
}
