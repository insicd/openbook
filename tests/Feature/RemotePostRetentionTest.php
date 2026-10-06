<?php

namespace Tests\Feature;

use App\Application\Queries\RemotePostRetentionQuery;
use App\Application\Services\InstanceSettings;
use App\Application\Services\RemotePostRetention;
use App\Domain\Comments\Comment;
use App\Domain\Communities\Community;
use App\Domain\Posts\Post;
use App\Domain\Reactions\Announce;
use App\Federation\Actors\Actor;
use App\Federation\Inbox\RemoteNoteUpserter;
use App\Infrastructure\Database\SystemSetting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class RemotePostRetentionTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function enable(): void
    {
        SystemSetting::put(InstanceSettings::KEY_REMOTE_POST_NON_PERTINENT_RETENTION_DAYS, '30');
        SystemSetting::put(InstanceSettings::KEY_REMOTE_POST_PERTINENT_RETENTION_DAYS, '90');
    }

    private function remote(): Actor
    {
        $name = 'r'.Str::random(12);

        return Actor::query()->create(['type' => Actor::TYPE_PERSON, 'is_local' => false,
            'preferred_username' => $name, 'domain' => 'remote.test', 'uri' => 'https://remote.test/users/'.$name]);
    }

    private function fixturePost(Actor $actor, int $age = 100, array $attributes = []): Post
    {
        $post = Post::query()->create(array_merge(['actor_id' => $actor->id, 'body' => 'Retention',
            'published_at' => now()->subYears(5), 'uri' => $actor->is_local ? null : 'https://remote.test/posts/'.Str::uuid()], $attributes));
        $post->forceFill(['created_at' => now()->subDays($age)])->saveQuietly();

        return $post;
    }

    public function test_multiple_batches_delete_both_categories_and_second_run_is_empty(): void
    {
        $this->enable();
        $local = $this->createFullAccount('retentionbatches')->actor;
        $remote = $this->remote();
        foreach (range(1, 5) as $i) {
            $this->fixturePost($remote);
            $home = $this->fixturePost($remote);
            Announce::query()->create(['actor_id' => $local->id, 'post_id' => $home->id, 'is_direct' => true]);
        }
        $this->assertSame(['nonPertinent' => 5, 'pertinent' => 5, 'timed_out' => false], app(RemotePostRetention::class)->prune(2, 10));
        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('announces', 0);
        $this->assertSame(['nonPertinent' => 0, 'pertinent' => 0, 'timed_out' => false], app(RemotePostRetention::class)->prune(2, 10));
    }

    public function test_cascades_remove_entire_remote_thread_reports_and_pivots_but_preserve_media_and_polymorphic_rows(): void
    {
        $this->enable();
        Storage::fake('public');
        Storage::disk('public')->put('retention.jpg', 'fixture');
        $user = $this->createFullAccount('retentioncascade');
        $remote = $this->remote();
        $post = $this->fixturePost($remote);
        $parent = Comment::query()->create(['post_id' => $post->id, 'actor_id' => $remote->id, 'body' => 'Remote']);
        $local = Comment::query()->create(['post_id' => $post->id, 'parent_comment_id' => $parent->id, 'actor_id' => $user->actor->id, 'body' => 'Local']);
        DB::table('reports')->insert(['id' => (string) Str::uuid(), 'reporter_id' => $user->id, 'post_id' => $post->id, 'reason' => 'spam', 'status' => 'open']);
        DB::table('reports')->insert(['id' => (string) Str::uuid(), 'reporter_id' => $user->id, 'comment_id' => $local->id, 'reason' => 'spam', 'status' => 'closed']);
        $media = (string) Str::uuid();
        DB::table('media')->insert(['id' => $media, 'actor_id' => $user->actor->id, 'disk' => 'public', 'path' => 'retention.jpg', 'mime_type' => 'image/jpeg', 'byte_size' => 7]);
        DB::table('post_attachments')->insert(['id' => (string) Str::uuid(), 'post_id' => $post->id, 'media_id' => $media, 'position' => 0]);
        DB::table('likes')->insert(['id' => (string) Str::uuid(), 'actor_id' => $user->actor->id, 'likeable_type' => 'comment', 'likeable_id' => $local->id]);
        DB::table('mentions')->insert(['id' => (string) Str::uuid(), 'actor_id' => $user->actor->id, 'mentionable_type' => 'post', 'mentionable_id' => $post->id]);
        DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'recipient_id' => $user->id, 'actor_id' => $remote->id, 'type' => 'comment', 'notifiable_type' => 'comment', 'notifiable_id' => $local->id]);
        $jobs = DB::table('jobs')->count();
        $this->assertSame(1, app(RemotePostRetention::class)->prune(10, 10)['pertinent']);
        foreach (['posts', 'comments', 'reports', 'post_attachments'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        foreach (['media', 'likes', 'mentions', 'notifications'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        Storage::disk('public')->assertExists('retention.jpg');
        $this->assertSame($jobs, DB::table('jobs')->count());
    }

    public function test_local_roots_quotes_and_direct_messages_are_preserved_with_their_comments(): void
    {
        $this->enable();
        $local = $this->createFullAccount('retentionpreserve')->actor;
        $remote = $this->remote();
        $root = $this->fixturePost($local);
        Comment::query()->create(['post_id' => $root->id, 'actor_id' => $remote->id, 'body' => 'Remote reply']);
        $quoted = $this->fixturePost($remote);
        $this->fixturePost($local, attributes: ['quoted_post_id' => $quoted->id]);
        $this->fixturePost($remote, attributes: ['visibility' => Post::VISIBILITY_DIRECT]);
        $this->fixturePost($remote, attributes: ['uri' => null]);
        $this->assertSame(['nonPertinent' => 0, 'pertinent' => 0, 'timed_out' => false], app(RemotePostRetention::class)->prune(2, 10));
        $this->assertDatabaseCount('posts', 5);
        $this->assertDatabaseCount('comments', 1);
    }

    public function test_policy_is_rechecked_after_initial_selection_for_new_local_comments_and_quotes(): void
    {
        $this->enable();
        $local = $this->createFullAccount('retentionrace')->actor;
        $remote = $this->remote();
        $recent = $this->fixturePost($remote, 40);
        $quoted = $this->fixturePost($remote);
        $remaining = $this->fixturePost($remote);
        $injected = false;
        DB::listen(function (QueryExecuted $query) use (&$injected, $recent, $quoted, $local): void {
            if (! $injected && str_contains($query->sql, 'retention_posts')) {
                $injected = true;
                Comment::query()->create(['post_id' => $recent->id, 'actor_id' => $local->id, 'body' => 'New local reply']);
                $this->fixturePost($local, attributes: ['quoted_post_id' => $quoted->id]);
            }
        });
        $result = app(RemotePostRetention::class)->prune(10, 10);
        $this->assertSame(1, $result['nonPertinent']);
        $this->assertSame(0, $result['pertinent']);
        $this->assertDatabaseMissing('posts', ['id' => $remaining->id]);
        $this->assertDatabaseHas('posts', ['id' => $recent->id]);
        $this->assertDatabaseHas('posts', ['id' => $quoted->id]);
    }

    public function test_follow_changes_are_rechecked_before_deleting(): void
    {
        $this->enable();
        $local = $this->createFullAccount('retentionfollowrace')->actor;
        $remote = $this->remote();
        $post = $this->fixturePost($remote, 40);
        $changed = false;
        DB::listen(function (QueryExecuted $query) use (&$changed, $local, $remote): void {
            if (! $changed && str_contains($query->sql, 'retention_posts')) {
                $changed = true;
                DB::table('follows')->insert(['id' => (string) Str::uuid(), 'follower_id' => $local->id, 'following_id' => $remote->id, 'status' => 'accepted', 'requested_at' => now()]);
            }
        });
        $this->assertSame(0, app(RemotePostRetention::class)->prune(1, 10)['nonPertinent']);
        $this->assertDatabaseHas('posts', ['id' => $post->id]);
    }

    public function test_database_error_rolls_back_current_batch(): void
    {
        $this->enable();
        $remote = $this->remote();
        $post = $this->fixturePost($remote);
        $parent = Comment::query()->create(['post_id' => $post->id, 'actor_id' => $remote->id, 'body' => 'Parent']);
        $child = Comment::query()->create(['post_id' => $post->id, 'parent_comment_id' => $parent->id, 'actor_id' => $remote->id, 'body' => 'Child']);
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'delete from')) {
                throw new RuntimeException('Simulated failure after DELETE');
            }
        });
        try {
            app(RemotePostRetention::class)->prune(1, 10);
            $this->fail('Expected a database error');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated failure after DELETE', $e->getMessage());
        }
        $this->assertDatabaseHas('posts', ['id' => $post->id]);
        $this->assertDatabaseHas('comments', ['id' => $child->id, 'parent_comment_id' => $parent->id]);
        $this->assertDatabaseCount('comments', 2);
    }

    public function test_reply_links_are_detached_in_one_update_only_for_threads_being_deleted(): void
    {
        $this->enable();
        $remote = $this->remote();
        $local = $this->createFullAccount('retentiondetach')->actor;
        $doomed = $this->fixturePost($remote);
        $kept = $this->fixturePost($local);
        $keptParent = Comment::query()->create(['post_id' => $kept->id, 'actor_id' => $remote->id, 'body' => 'Parent']);
        $keptChild = Comment::query()->create(['post_id' => $kept->id, 'parent_comment_id' => $keptParent->id, 'actor_id' => $remote->id, 'body' => 'Child']);
        $parent = null;
        foreach (range(1, 5) as $depth) {
            $parent = Comment::query()->create(['post_id' => $doomed->id, 'parent_comment_id' => $parent?->id, 'actor_id' => $remote->id, 'body' => 'Reply']);
        }
        $updates = 0;
        DB::listen(function (QueryExecuted $query) use (&$updates, $doomed, $keptChild, $keptParent): void {
            if (preg_match('/^update ["`]comments["`]/', $query->sql)) {
                $updates++;
                $this->assertSame(0, Comment::query()->where('post_id', $doomed->id)->whereNotNull('parent_comment_id')->count());
                $this->assertDatabaseHas('comments', ['id' => $keptChild->id, 'parent_comment_id' => $keptParent->id]);
            }
        });
        $this->assertSame(1, app(RemotePostRetention::class)->prune(10, 10)['nonPertinent']);
        $this->assertSame(1, $updates);
        $this->assertDatabaseMissing('posts', ['id' => $doomed->id]);
        $this->assertDatabaseCount('comments', 2);
    }

    public function test_expired_uri_can_be_imported_again_with_a_new_id_and_import_date(): void
    {
        $this->enable();
        $remote = $this->remote();
        $expired = $this->fixturePost($remote);
        app(RemotePostRetention::class)->prune(1, 10);
        $this->travelTo(now()->startOfSecond());
        $new = app(RemoteNoteUpserter::class)->upsertPost(['type' => 'Note', 'to' => ['https://www.w3.org/ns/activitystreams#Public']], $expired->uri, $remote, 'Imported again', now()->subYears(5), notifyMentions: false, resolveQuote: false);
        $this->assertNotSame($expired->id, $new->id);
        $this->assertTrue($new->created_at->equalTo(now()));
        $this->assertSame([], app(RemotePostRetentionQuery::class)->nonPertinent(10)->pluck('id')->all());
    }

    public function test_time_limit_stops_between_batches_and_can_resume(): void
    {
        $this->enable();
        $remote = $this->remote();
        $this->fixturePost($remote);
        $this->fixturePost($remote);
        $delayed = false;
        DB::listen(function (QueryExecuted $query) use (&$delayed): void {
            if (! $delayed && str_contains($query->sql, 'retention_posts')) {
                $delayed = true;
                usleep(1_100_000);
            }
        });
        $result = app(RemotePostRetention::class)->prune(1, 1);
        $this->assertTrue($result['timed_out']);
        $this->assertSame(1, $result['nonPertinent']);
        $this->assertDatabaseCount('posts', 1);
        $this->assertSame(1, app(RemotePostRetention::class)->prune(1, 10)['nonPertinent']);
    }

    public function test_overlapping_command_is_skipped_and_lock_is_released_after_run(): void
    {
        $this->enable();
        $post = $this->fixturePost($this->remote());
        $lock = fopen(storage_path('framework/cache/remote-post-retention.lock'), 'c');
        flock($lock, LOCK_EX);
        try {
            $this->artisan('openbook:prune-remote-posts')->expectsOutputToContain('già in esecuzione')->assertSuccessful();
            $this->assertDatabaseHas('posts', ['id' => $post->id]);
        } finally {
            fclose($lock);
        }
        $this->artisan('openbook:prune-remote-posts', ['--batch-size' => 1])->expectsOutputToContain('Non pertinenti 1')->assertSuccessful();
        $lock = fopen(storage_path('framework/cache/remote-post-retention.lock'), 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        fclose($lock);
    }

    public function test_remote_retention_preserves_the_local_community_post_counter(): void
    {
        $this->enable();
        $user = $this->createFullAccount('retentioncommunitycounter');
        $group = $this->remote();
        $group->update(['type' => Actor::TYPE_GROUP]);
        $community = Community::query()->create(['actor_id' => $group->id, 'owner_user_id' => $user->id, 'slug' => 'retention-counter', 'posts_count' => 1]);
        $this->fixturePost($user->actor, attributes: ['community_id' => $community->id]);
        $this->fixturePost($this->remote(), attributes: ['community_id' => $community->id]);
        $this->assertSame(1, app(RemotePostRetention::class)->prune(1, 10)['nonPertinent']);
        $this->assertSame(1, $community->fresh()->posts_count);
    }

    public function test_command_releases_lock_and_rolls_back_batch_on_error(): void
    {
        $this->enable();
        $post = $this->fixturePost($this->remote());
        $failed = false;
        DB::listen(function (QueryExecuted $query) use (&$failed): void {
            if (! $failed && str_starts_with($query->sql, 'delete from')) {
                $failed = true;
                throw new RuntimeException('Batch failed');
            }
        });
        try {
            Artisan::call('openbook:prune-remote-posts');
            $this->fail('Expected the batch failure');
        } catch (RuntimeException $e) {
            $this->assertSame('Batch failed', $e->getMessage());
        }
        $this->assertDatabaseHas('posts', ['id' => $post->id]);
        $lock = fopen(storage_path('framework/cache/remote-post-retention.lock'), 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        fclose($lock);
        $this->artisan('openbook:prune-remote-posts')->assertSuccessful();
        $this->assertDatabaseMissing('posts', ['id' => $post->id]);
    }

    public function test_durations_are_fixed_for_the_run_even_if_settings_change_between_batches(): void
    {
        $this->enable();
        $remote = $this->remote();
        foreach (range(1, 3) as $i) {
            $this->fixturePost($remote, 40);
        }
        $changed = false;
        DB::listen(function (QueryExecuted $query) use (&$changed): void {
            if (! $changed && str_starts_with($query->sql, 'delete from')) {
                $changed = true;
                SystemSetting::put(InstanceSettings::KEY_REMOTE_POST_NON_PERTINENT_RETENTION_DAYS, '365');
            }
        });
        $this->assertSame(3, app(RemotePostRetention::class)->prune(1, 10)['nonPertinent']);
        $this->assertDatabaseCount('posts', 0);
    }
}
