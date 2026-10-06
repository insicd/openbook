<?php

namespace Tests\Feature;

use App\Application\Services\DatabaseSanity;
use App\Domain\Comments\Comment;
use App\Domain\Events\Event;
use App\Domain\Events\EventComment;
use App\Domain\Events\EventParticipation;
use App\Domain\Moderation\AuditLog;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class DatabaseSanityNotificationTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public static function parentTypes(): array
    {
        return [['post'], ['comment'], ['follow'], ['actor'], ['event'], ['event_comment'], ['event_participation']];
    }

    #[DataProvider('parentTypes')]
    public function test_cleans_orphans_and_push_rows_and_only_bumps_affected_recipients(string $type): void
    {
        $first = $this->createFullAccount('sanityfirst');
        $second = $this->createFullAccount('sanitysecond');
        $unaffected = $this->createFullAccount('sanityunaffected');
        $post = Post::query()->create(['actor_id' => $first->actor->id, 'body' => 'Fixture', 'status' => 'deleted', 'published_at' => now()]);
        $event = Event::query()->create(['uri' => 'https://example.test/event', 'name' => 'Fixture', 'visibility' => 'public', 'status' => 'deleted', 'start_at' => now()]);
        $parent = match ($type) {
            'post' => $post,
            'comment' => Comment::query()->create(['post_id' => $post->id, 'actor_id' => $first->actor->id, 'body' => 'Fixture', 'status' => 'deleted']),
            'follow' => Follow::query()->create(['follower_id' => $first->actor->id, 'following_id' => $second->actor->id, 'status' => 'accepted', 'requested_at' => now()]),
            'actor' => $first->actor,
            'event' => $event,
            'event_comment' => EventComment::query()->create(['event_id' => $event->id, 'actor_id' => $first->actor->id, 'body' => 'Fixture', 'status' => 'deleted']),
            'event_participation' => EventParticipation::query()->create(['event_id' => $event->id, 'actor_id' => $first->actor->id, 'status' => 'rejected']),
        };
        $valid = $this->notification($unaffected->id, $type, $parent->id);
        $unknown = $this->notification($unaffected->id, 'future_type', (string) Str::uuid());
        $orphans = [
            $this->notification($first->id, $type, (string) Str::uuid()),
            $this->notification($first->id, $type, (string) Str::uuid(), true),
            $this->notification($second->id, $type, (string) Str::uuid()),
        ];
        AuditLog::query()->create(['action' => 'test', 'subject_type' => $type, 'subject_id' => (string) Str::uuid(), 'created_at' => now()]);
        AuditLog::query()->create(['action' => 'test', 'created_at' => now()]);
        $auditCount = DB::table('audit_logs')->count();
        DB::enableQueryLog();

        $result = app(DatabaseSanity::class)->reconcileBatch('notifications', $type, 10);
        $this->assertSame(3, $result['deleted']);
        $this->assertSame(max($orphans), $result['cursor']);
        $updates = array_filter(DB::getQueryLog(), fn (array $query): bool => str_starts_with($query['query'], 'update "users"'));
        $this->assertCount(1, $updates);
        DB::disableQueryLog();
        $this->assertSame(1, $first->fresh()->notifications_revision);
        $this->assertSame(1, $second->fresh()->notifications_revision);
        $this->assertSame(0, $unaffected->fresh()->notifications_revision);
        foreach ($orphans as $id) {
            $this->assertDatabaseMissing('notifications', ['id' => $id]);
            $this->assertDatabaseMissing('push_notifications', ['notification_id' => $id]);
        }
        foreach ([$valid, $unknown] as $id) {
            $this->assertDatabaseHas('notifications', ['id' => $id]);
            $this->assertDatabaseHas('push_notifications', ['notification_id' => $id]);
        }
        $this->assertDatabaseHas($parent->getTable(), ['id' => $parent->id]);
        $this->assertDatabaseCount('audit_logs', $auditCount);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame(['deleted' => 0, 'cursor' => null], app(DatabaseSanity::class)->reconcileBatch('notifications', $type, 10));
        $this->assertSame(1, $first->fresh()->notifications_revision);
        $this->assertSame(1, $second->fresh()->notifications_revision);
    }

    public function test_batches_bump_the_same_recipient_once_per_batch(): void
    {
        $user = $this->createFullAccount('batchrecipient');
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->notification($user->id, 'post', (string) Str::uuid());
        }
        sort($ids);
        $service = app(DatabaseSanity::class);
        $first = $service->reconcileBatch('notifications', 'post', 2);
        $second = $service->reconcileBatch('notifications', 'post', 2, $first['cursor']);
        $third = $service->reconcileBatch('notifications', 'post', 2, $second['cursor']);
        $this->assertSame(['deleted' => 2, 'cursor' => $ids[1]], $first);
        $this->assertSame(['deleted' => 2, 'cursor' => $ids[3]], $second);
        $this->assertSame(['deleted' => 1, 'cursor' => $ids[4]], $third);
        $this->assertSame(3, $user->fresh()->notifications_revision);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('push_notifications', 0);
        $this->assertSame(['deleted' => 0, 'cursor' => null], $service->reconcileBatch('notifications', 'post', 2));
        $this->assertSame(3, $user->fresh()->notifications_revision);
    }

    public function test_a_restored_parent_does_not_bump_the_preserved_recipient(): void
    {
        $preserved = $this->createFullAccount('preservedrecipient');
        $deleted = $this->createFullAccount('deletedrecipient');
        $parentId = (string) Str::uuid();
        $valid = $this->notification($preserved->id, 'post', $parentId);
        $orphan = $this->notification($deleted->id, 'post', (string) Str::uuid());
        $restored = false;
        DB::listen(function (QueryExecuted $query) use ($parentId, $preserved, &$restored): void {
            if (! $restored && str_starts_with($query->sql, 'select ') && str_contains($query->sql, '"recipient_id"') && str_contains($query->sql, 'from "notifications"')) {
                $restored = true;
                DB::table('posts')->insert(['id' => $parentId, 'actor_id' => $preserved->actor->id, 'body' => 'Restored', 'published_at' => now()]);
            }
        });

        $this->assertSame(1, app(DatabaseSanity::class)->reconcileBatch('notifications', 'post', 10)['deleted']);
        $this->assertTrue($restored);
        $this->assertSame(0, $preserved->fresh()->notifications_revision);
        $this->assertSame(1, $deleted->fresh()->notifications_revision);
        $this->assertDatabaseHas('notifications', ['id' => $valid]);
        $this->assertDatabaseHas('push_notifications', ['notification_id' => $valid]);
        $this->assertDatabaseMissing('notifications', ['id' => $orphan]);
        $this->assertDatabaseMissing('push_notifications', ['notification_id' => $orphan]);
    }

    public function test_failure_updating_revisions_rolls_back_notifications_and_push_deletion(): void
    {
        $user = $this->createFullAccount('rollbackrecipient');
        $id = $this->notification($user->id, 'post', (string) Str::uuid());
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'update "users"') && str_contains($query->sql, 'notifications_revision')) {
                throw new RuntimeException('Injected revision failure');
            }
        });
        try {
            app(DatabaseSanity::class)->reconcileBatch('notifications', 'post', 10);
            $this->fail('Expected a revision failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected revision failure', $exception->getMessage());
        }
        $this->assertDatabaseHas('notifications', ['id' => $id]);
        $this->assertDatabaseHas('push_notifications', ['notification_id' => $id]);
        $this->assertSame(0, $user->fresh()->notifications_revision);
    }

    public function test_cleanup_invalidates_the_polling_etag_and_unread_count(): void
    {
        $user = $this->createFullAccount('pollingrecipient');
        $this->notification($user->id, 'post', (string) Str::uuid());
        $before = $this->actingAs($user)->getJson(route('notifications.feed'));
        $before->assertOk()->assertJsonPath('unread_count', 1);
        $etag = $before->headers->get('ETag');

        app(DatabaseSanity::class)->reconcileBatch('notifications', 'post', 10);

        $after = $this->withHeader('If-None-Match', $etag)->getJson(route('notifications.feed'));
        $after->assertOk()->assertJsonPath('unread_count', 0)->assertJsonCount(0, 'notifications');
        $this->assertNotSame($etag, $after->headers->get('ETag'));
    }

    private function notification(string $recipientId, string $type, string $parentId, bool $read = false): string
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert(['id' => $id, 'recipient_id' => $recipientId, 'type' => 'mention', 'notifiable_type' => $type, 'notifiable_id' => $parentId, 'read_at' => $read ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('push_notifications')->insert(['id' => (string) Str::uuid(), 'notification_id' => $id]);

        return $id;
    }
}
