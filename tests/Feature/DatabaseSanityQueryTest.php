<?php

namespace Tests\Feature;

use App\Application\Queries\DatabaseSanityQuery;
use App\Domain\Comments\Comment;
use App\Domain\Events\Event;
use App\Domain\Events\EventComment;
use App\Domain\Events\EventParticipation;
use App\Domain\Moderation\AuditLog;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class DatabaseSanityQueryTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public static function supportedRelations(): array
    {
        $cases = [];
        foreach ([
            'likes' => ['post', 'comment', 'event', 'event_comment'],
            'mentions' => ['post', 'comment', 'event', 'event_comment'],
            'notifications' => ['post', 'comment', 'follow', 'actor', 'event', 'event_comment', 'event_participation'],
        ] as $table => $types) {
            foreach ($types as $type) {
                $cases[$table.'/'.$type] = [$table, $type];
            }
        }

        return $cases;
    }

    #[DataProvider('supportedRelations')]
    public function test_selects_only_missing_parents_in_stable_limited_batches(string $table, string $type): void
    {
        $user = $this->createFullAccount('sanity');
        $post = Post::query()->create(['actor_id' => $user->actor->id, 'body' => 'Fixture', 'visibility' => 'public', 'status' => 'deleted', 'published_at' => now()]);
        $event = Event::query()->create(['actor_id' => $user->actor->id, 'uri' => 'https://example.test/event', 'name' => 'Fixture', 'visibility' => 'public', 'status' => 'deleted', 'start_at' => now()]);
        $parent = match ($type) {
            'post' => $post,
            'comment' => Comment::query()->create(['post_id' => $post->id, 'actor_id' => $user->actor->id, 'body' => 'Fixture', 'status' => 'deleted']),
            'event' => $event,
            'event_comment' => EventComment::query()->create(['event_id' => $event->id, 'actor_id' => $user->actor->id, 'body' => 'Fixture', 'status' => 'deleted']),
            'event_participation' => EventParticipation::query()->create(['event_id' => $event->id, 'actor_id' => $user->actor->id, 'status' => 'rejected']),
            'follow' => Follow::query()->create(['follower_id' => $user->actor->id, 'following_id' => $user->actor->id, 'status' => 'accepted', 'requested_at' => now()]),
            'actor' => $user->actor,
        };
        $query = app(DatabaseSanityQuery::class);
        $this->assertSame($parent->getTable(), $query->relations()[$table]['parents'][$type]);
        $this->insertRelation($table, $type, $parent->id, $user->id, $user->actor->id);
        $orphans = [];
        for ($i = 0; $i < 3; $i++) {
            $orphans[] = $this->insertRelation($table, $type, (string) Str::uuid(), $user->id, $user->actor->id);
        }
        sort($orphans);
        // An ID in another parent table does not validate this relation.
        if ($type !== 'actor') {
            $wrongParent = $this->insertRelation($table, $type, $user->actor->id, $user->id, $user->actor->id);
            $orphans[] = $wrongParent;
            sort($orphans);
        }
        $this->insertRelation($table, 'unknown', (string) Str::uuid(), $user->id, $user->actor->id);

        DB::enableQueryLog();
        $first = $query->orphans($table, $type, 2)->pluck('id')->all();
        $second = $query->orphans($table, $type, 2, end($first))->pluck('id')->all();
        $this->assertSame($orphans, array_merge($first, $second));
        $this->assertSame([], $query->orphans($table, $type, 2, end($second))->pluck('id')->all());
        foreach (DB::getQueryLog() as $statement) {
            $this->assertStringStartsWith('select ', $statement['query']);
        }
        DB::disableQueryLog();
        $this->assertDatabaseCount($table, count($orphans) + 2);
        $this->assertDatabaseHas($parent->getTable(), ['id' => $parent->id]);
    }

    public function test_reports_unknown_types_without_guessing_parents_or_touching_audit(): void
    {
        $user = $this->createFullAccount('unknownsanity');
        $query = app(DatabaseSanityQuery::class);
        $audit = AuditLog::query()->create(['action' => 'test', 'subject_type' => 'post', 'subject_id' => (string) Str::uuid(), 'created_at' => now()]);
        foreach (['likes', 'mentions', 'notifications'] as $table) {
            $this->insertRelation($table, Post::class, (string) Str::uuid(), $user->id, $user->actor->id);
            $this->insertRelation($table, 'future_type', (string) Str::uuid(), $user->id, $user->actor->id);
            $this->insertRelation($table, 'future_type', (string) Str::uuid(), $user->id, $user->actor->id);
            $this->assertSame([Post::class => 1, 'future_type' => 2], $query->unknownTypes($table)->pluck('row_count', 'type')->all());
            $this->assertCount(1, $query->unknownTypes($table, 1)->get());
            $this->assertDatabaseCount($table, 3);
        }
        $this->assertSame(['likes', 'mentions', 'notifications'], array_keys($query->relations()));
        $this->assertDatabaseHas('audit_logs', ['id' => $audit->id]);
    }

    public function test_a_physically_removed_parent_becomes_an_orphan(): void
    {
        $user = $this->createFullAccount('removedparent');
        $post = Post::query()->create(['actor_id' => $user->actor->id, 'body' => 'Fixture', 'visibility' => 'direct', 'status' => 'published', 'published_at' => now()]);
        $query = app(DatabaseSanityQuery::class);
        $ids = [];
        foreach (array_keys($query->relations()) as $table) {
            $ids[$table] = $this->insertRelation($table, 'post', $post->id, $user->id, $user->actor->id);
            $this->assertSame([], $query->orphans($table, 'post', 10)->pluck('id')->all());
        }
        DB::table('posts')->where('id', $post->id)->delete();
        foreach ($ids as $table => $id) {
            $this->assertSame([$id], $query->orphans($table, 'post', 10)->pluck('id')->all());
            $this->assertDatabaseHas($table, ['id' => $id]);
        }
        // Restoring the same ID changes the next selection immediately.
        DB::table('posts')->insert(['id' => $post->id, 'actor_id' => $user->actor->id, 'body' => 'Restored', 'published_at' => now()]);
        foreach (array_keys($ids) as $table) {
            $this->assertSame([], $query->orphans($table, 'post', 10)->pluck('id')->all());
        }
    }

    public function test_batch_index_migration_can_be_rolled_back_and_reapplied(): void
    {
        $migration = require database_path('migrations/2026_10_06_230000_add_database_sanity_batch_indexes.php');
        $migration->down();
        foreach (['likes', 'mentions', 'notifications'] as $table) {
            $this->assertFalse(Schema::hasIndex($table, $table.'_sanity_batch_index'));
        }
        $migration->up();
        foreach (['likes', 'mentions', 'notifications'] as $table) {
            $this->assertTrue(Schema::hasIndex($table, $table.'_sanity_batch_index'));
        }
    }

    public static function invalidSelections(): array
    {
        return [['audit_logs', 'post', 10], ['posts', 'post', 10], ['likes', 'actor', 10], ['notifications', 'future_type', 10], ['likes', 'post', 0], ['mentions', 'post', -1]];
    }

    #[DataProvider('invalidSelections')]
    public function test_rejects_unsupported_tables_types_and_nonpositive_limits(string $table, string $type, int $limit): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(DatabaseSanityQuery::class)->orphans($table, $type, $limit);
    }

    private function insertRelation(string $table, string $type, string $parentId, string $userId, string $actorId): string
    {
        $id = (string) Str::uuid();
        $morph = app(DatabaseSanityQuery::class)->relations()[$table]['morph'];
        $attributes = ['id' => $id, $morph.'_type' => $type, $morph.'_id' => $parentId, 'actor_id' => $actorId];
        if ($table === 'notifications') {
            $attributes += ['recipient_id' => $userId, 'type' => 'mention'];
        }
        DB::table($table)->insert($attributes);

        return $id;
    }
}
