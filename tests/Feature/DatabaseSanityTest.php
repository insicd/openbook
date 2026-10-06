<?php

namespace Tests\Feature;

use App\Application\Queries\DatabaseSanityQuery;
use App\Application\Services\DatabaseSanity;
use App\Application\Services\InstanceSettings;
use App\Domain\Comments\Comment;
use App\Domain\Events\Event;
use App\Domain\Events\EventComment;
use App\Domain\Moderation\AuditLog;
use App\Domain\Posts\Post;
use App\Infrastructure\Database\SystemSetting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class DatabaseSanityTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public static function supportedRelations(): array
    {
        $cases = [];
        foreach (['likes', 'mentions'] as $table) {
            foreach (['post', 'comment', 'event', 'event_comment'] as $type) {
                $cases[$table.'/'.$type] = [$table, $type];
            }
        }

        return $cases;
    }

    #[DataProvider('supportedRelations')]
    public function test_deletes_only_orphans_in_repeatable_batches_with_retention_disabled(string $table, string $type): void
    {
        $user = $this->createFullAccount('sanitycleanup');
        $post = Post::query()->create(['actor_id' => $user->actor->id, 'body' => 'Fixture', 'status' => 'deleted', 'published_at' => now()]);
        $event = Event::query()->create(['actor_id' => $user->actor->id, 'uri' => 'https://example.test/event', 'name' => 'Fixture', 'visibility' => 'public', 'status' => 'deleted', 'start_at' => now()]);
        $parent = match ($type) {
            'post' => $post,
            'comment' => Comment::query()->create(['post_id' => $post->id, 'actor_id' => $user->actor->id, 'body' => 'Fixture', 'status' => 'deleted']),
            'event' => $event,
            'event_comment' => EventComment::query()->create(['event_id' => $event->id, 'actor_id' => $user->actor->id, 'body' => 'Fixture', 'status' => 'deleted']),
        };
        SystemSetting::put(InstanceSettings::KEY_REMOTE_POST_NON_PERTINENT_RETENTION_DAYS, '0');
        SystemSetting::put(InstanceSettings::KEY_REMOTE_POST_PERTINENT_RETENTION_DAYS, '0');
        $valid = $this->insertRelation($table, $type, $parent->id, $user->actor->id);
        $unknown = $this->insertRelation($table, 'future_type', (string) Str::uuid(), $user->actor->id);
        $otherType = $this->insertRelation($table, $type === 'post' ? 'comment' : 'post', (string) Str::uuid(), $user->actor->id);
        $orphans = [];
        for ($i = 0; $i < 5; $i++) {
            $orphans[] = $this->insertRelation($table, $type, (string) Str::uuid(), $user->actor->id);
        }
        sort($orphans);
        $service = app(DatabaseSanity::class);
        DB::enableQueryLog();
        $first = $service->reconcileBatch($table, $type, 2);
        $second = $service->reconcileBatch($table, $type, 2, $first['cursor']);
        $third = $service->reconcileBatch($table, $type, 2, $second['cursor']);
        $empty = $service->reconcileBatch($table, $type, 2, $third['cursor']);
        $this->assertSame(['deleted' => 2, 'cursor' => $orphans[1]], $first);
        $this->assertSame(['deleted' => 2, 'cursor' => $orphans[3]], $second);
        $this->assertSame(['deleted' => 1, 'cursor' => $orphans[4]], $third);
        $this->assertSame(['deleted' => 0, 'cursor' => null], $empty);
        $this->assertSame($empty, $service->reconcileBatch($table, $type, 2));
        $deletes = array_filter(DB::getQueryLog(), fn (array $query): bool => str_starts_with($query['query'], 'delete '));
        $this->assertCount(3, $deletes);
        foreach ($deletes as $delete) {
            $this->assertStringContainsString('not exists', $delete['query']);
        }
        DB::disableQueryLog();
        foreach ([$valid, $unknown, $otherType] as $id) {
            $this->assertDatabaseHas($table, ['id' => $id]);
        }
        $this->assertDatabaseCount($table, 3);
        $this->assertDatabaseHas($parent->getTable(), ['id' => $parent->id]);
        $this->assertDatabaseCount('jobs', 0);
    }

    public static function cleanupTables(): array
    {
        return [['likes'], ['mentions']];
    }

    #[DataProvider('cleanupTables')]
    public function test_preserves_a_parent_created_after_the_initial_selection(string $table): void
    {
        $user = $this->createFullAccount('restoredparent');
        $parentId = (string) Str::uuid();
        $id = $this->insertRelation($table, 'post', $parentId, $user->actor->id);
        $restored = false;
        DB::listen(function (QueryExecuted $query) use ($table, $parentId, $user, &$restored): void {
            if (! $restored && str_starts_with($query->sql, 'select ') && str_contains($query->sql, 'from "'.$table.'"')) {
                $restored = true;
                DB::table('posts')->insert(['id' => $parentId, 'actor_id' => $user->actor->id, 'body' => 'Restored parent', 'published_at' => now()]);
            }
        });

        $this->assertSame(['deleted' => 0, 'cursor' => $id], app(DatabaseSanity::class)->reconcileBatch($table, 'post', 10));
        $this->assertTrue($restored);
        $this->assertDatabaseHas($table, ['id' => $id]);
        $this->assertDatabaseHas('posts', ['id' => $parentId]);
        $this->assertSame(['deleted' => 0, 'cursor' => null], app(DatabaseSanity::class)->reconcileBatch($table, 'post', 10));
    }

    public function test_keeps_notifications_audit_and_other_tables_untouched(): void
    {
        $user = $this->createFullAccount('excludedcleanup');
        $parentId = (string) Str::uuid();
        $like = $this->insertRelation('likes', 'post', $parentId, $user->actor->id);
        $mention = $this->insertRelation('mentions', 'post', $parentId, $user->actor->id);
        DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'recipient_id' => $user->id, 'actor_id' => $user->actor->id, 'type' => 'mention', 'notifiable_type' => 'post', 'notifiable_id' => $parentId]);
        AuditLog::query()->create(['action' => 'test', 'subject_type' => 'post', 'subject_id' => $parentId, 'created_at' => now()]);
        AuditLog::query()->create(['action' => 'test', 'created_at' => now()]);
        $auditCount = DB::table('audit_logs')->count();

        $this->assertSame(1, app(DatabaseSanity::class)->reconcileBatch('likes', 'post', 10)['deleted']);
        $this->assertDatabaseMissing('likes', ['id' => $like]);
        $this->assertDatabaseHas('mentions', ['id' => $mention]);
        $this->assertSame(1, app(DatabaseSanity::class)->reconcileBatch('mentions', 'post', 10)['deleted']);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('audit_logs', $auditCount);
        $this->assertSame(0, $user->fresh()->notifications_revision);
    }

    #[DataProvider('cleanupTables')]
    public function test_rechecks_morph_type_and_does_not_delete_new_unselected_rows(string $table): void
    {
        $user = $this->createFullAccount('changedrelation');
        $id = $this->insertRelation($table, 'post', (string) Str::uuid(), $user->actor->id);
        $morph = app(DatabaseSanityQuery::class)->relations()[$table]['morph'];
        $newId = null;
        DB::listen(function (QueryExecuted $query) use ($table, $id, $morph, $user, &$newId): void {
            if ($newId === null && str_starts_with($query->sql, 'select ') && str_contains($query->sql, 'from "'.$table.'"')) {
                $newId = 'pending';
                DB::table($table)->where('id', $id)->update([$morph.'_type' => 'future_type']);
                $newId = $this->insertRelation($table, 'post', (string) Str::uuid(), $user->actor->id);
            }
        });

        $service = app(DatabaseSanity::class);
        $this->assertSame(['deleted' => 0, 'cursor' => $id], $service->reconcileBatch($table, 'post', 1));
        $this->assertDatabaseHas($table, ['id' => $id, $morph.'_type' => 'future_type']);
        $this->assertDatabaseHas($table, ['id' => $newId]);
        $this->assertSame(['deleted' => 1, 'cursor' => $newId], $service->reconcileBatch($table, 'post', 1));
        $this->assertDatabaseMissing($table, ['id' => $newId]);
        $this->assertDatabaseHas($table, ['id' => $id]);
    }

    public static function invalidSelections(): array
    {
        return [['notifications', 'unknown', 10], ['audit_logs', 'post', 10], ['posts', 'post', 10], ['likes', 'actor', 10], ['mentions', 'unknown', 10], ['likes', 'post', 0], ['mentions', 'post', -1]];
    }

    #[DataProvider('invalidSelections')]
    public function test_rejects_unsupported_cleanup_tables_types_and_limits(string $table, string $type, int $limit): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(DatabaseSanity::class)->reconcileBatch($table, $type, $limit);
    }

    private function insertRelation(string $table, string $type, string $parentId, string $actorId): string
    {
        $id = (string) Str::uuid();
        $morph = app(DatabaseSanityQuery::class)->relations()[$table]['morph'];
        DB::table($table)->insert(['id' => $id, 'actor_id' => $actorId, $morph.'_type' => $type, $morph.'_id' => $parentId]);

        return $id;
    }
}
