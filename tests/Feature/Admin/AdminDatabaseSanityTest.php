<?php

namespace Tests\Feature\Admin;

use App\Application\Queries\DatabaseSanityQuery;
use App\Domain\Posts\Post;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class AdminDatabaseSanityTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_preview_matches_cli_samples_without_writes_and_only_loads_on_its_tab(): void
    {
        $admin = $this->createFullAccount('sanityadmin', ['is_admin' => true]);
        for ($i = 0; $i < 102; $i++) {
            DB::table('likes')->insert(['id' => (string) Str::uuid(), 'actor_id' => $admin->actor->id, 'likeable_type' => 'post', 'likeable_id' => (string) Str::uuid()]);
        }
        $expected = app(DatabaseSanityQuery::class)->samples(100);
        $writes = $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$writes, &$queries): void {
            $queries[] = $query->sql;
            if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop|truncate)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $this->actingAs($admin)->get(route('admin.database.index', ['tab' => 'retention']))
            ->assertOk()->assertViewHas('sanityPreview', null);
        $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'sanity_parent')));
        $response = $this->get(route('admin.database.index', ['tab' => 'sanity', 'preview' => 1, 'batch-size' => 1000]))
            ->assertOk()->assertViewHas('activeTab', 'sanity')->assertViewHas('tables', [])
            ->assertViewHas('preview', null)->assertViewHas('sanityPreview', $expected)
            ->assertSee(__('openbook.admin.database.sanity_col_sample'))
            ->assertDontSee('name="remote_post_pertinent_retention_days"', false);
        $this->assertSame([], $writes);
        $this->assertDatabaseCount('likes', 102);
        $this->assertSame(100, $response->viewData('sanityPreview')['likes']['post']);
        $this->assertGreaterThan(strpos($response->getContent(), '</table>'), strpos($response->getContent(), __('openbook.admin.database.sanity_run')));
    }

    public function test_cleanup_redirects_to_sanity_and_reports_actual_deletions_and_audit(): void
    {
        $admin = $this->createFullAccount('cleanadmin', ['is_admin' => true]);
        $post = Post::query()->create(['actor_id' => $admin->actor->id, 'body' => 'PRIVATE-BODY', 'published_at' => now()]);
        $valid = (string) Str::uuid();
        DB::table('likes')->insert(['id' => $valid, 'actor_id' => $admin->actor->id, 'likeable_type' => 'post', 'likeable_id' => $post->id]);
        DB::table('likes')->insert(['id' => (string) Str::uuid(), 'actor_id' => $admin->actor->id, 'likeable_type' => 'post', 'likeable_id' => (string) Str::uuid()]);
        DB::table('mentions')->insert(['id' => (string) Str::uuid(), 'actor_id' => $admin->actor->id, 'mentionable_type' => 'comment', 'mentionable_id' => (string) Str::uuid()]);
        $notification = $this->orphanNotification($admin->id);

        $this->actingAs($admin)->post(route('admin.database.sanity.run'))
            ->assertRedirect(route('admin.database.index', ['tab' => 'sanity']))
            ->assertSessionHas('status', __('openbook.admin.database.sanity_completed', ['count' => 3]))
            ->assertSessionHas('sanityResult', fn (array $result): bool => $result['deleted']['notifications']['post'] === 1 && ! $result['timed_out']);
        $this->assertDatabaseHas('likes', ['id' => $valid]);
        $this->assertDatabaseHas('posts', ['id' => $post->id]);
        $this->assertDatabaseMissing('notifications', ['id' => $notification]);
        $this->assertDatabaseMissing('push_notifications', ['notification_id' => $notification]);
        $this->assertSame(1, $admin->fresh()->notifications_revision);
        $audit = DB::table('audit_logs')->where('action', 'database.sanity')->sole();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame(1, json_decode($audit->meta, true)['deleted']['likes']['post']);
        $this->assertDatabaseCount('jobs', 0);
        $this->get(route('admin.database.index', ['tab' => 'sanity']))
            ->assertOk()->assertSee(__('openbook.admin.database.sanity_col_deleted'))
            ->assertDontSee('PRIVATE-BODY')->assertViewHas('sanityPreview', fn (array $counts): bool => $counts['likes']['post'] === 0);
    }

    public function test_busy_cli_lock_blocks_web_cleanup_without_audit_or_deletions(): void
    {
        $admin = $this->createFullAccount('busyadmin', ['is_admin' => true]);
        $id = $this->orphanNotification($admin->id);
        $lock = fopen(storage_path('framework/cache/database-sanity.lock'), 'c');
        flock($lock, LOCK_EX);
        try {
            $this->actingAs($admin)->post(route('admin.database.sanity.run'))
                ->assertRedirect(route('admin.database.index', ['tab' => 'sanity']))
                ->assertSessionHas('status', __('openbook.admin.database.sanity_busy'));
        } finally {
            fclose($lock);
        }
        $this->assertDatabaseHas('notifications', ['id' => $id]);
        $this->assertSame(0, $admin->fresh()->notifications_revision);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'database.sanity']);
    }

    public function test_web_limits_are_fixed_and_partial_cleanup_can_be_repeated(): void
    {
        $admin = $this->createFullAccount('limitedadmin', ['is_admin' => true]);
        for ($i = 0; $i < 102; $i++) {
            DB::table('likes')->insert(['id' => (string) Str::uuid(), 'actor_id' => $admin->actor->id, 'likeable_type' => 'post', 'likeable_id' => (string) Str::uuid()]);
        }
        $delayed = false;
        DB::listen(function (QueryExecuted $query) use (&$delayed): void {
            if (! $delayed && str_starts_with($query->sql, 'delete ')) {
                $delayed = true;
                usleep(5_100_000);
            }
        });
        $this->actingAs($admin)->post(route('admin.database.sanity.run'), ['batch-size' => 1000, 'max-time' => 1800])
            ->assertRedirect()->assertSessionHas('sanityResult', fn (array $result): bool => $result['timed_out'] && $result['deleted']['likes']['post'] === 100);
        $this->assertDatabaseCount('likes', 2);
        $this->get(route('admin.database.index', ['tab' => 'sanity']))->assertOk()->assertSee(__('openbook.admin.database.sanity_partial'));
        $this->post(route('admin.database.sanity.run'))
            ->assertRedirect()->assertSessionHas('sanityResult', fn (array $result): bool => ! $result['timed_out'] && $result['deleted']['likes']['post'] === 2);
        $this->assertDatabaseCount('likes', 0);
    }

    public function test_unknown_types_are_escaped_and_preserved(): void
    {
        $admin = $this->createFullAccount('unknownweb', ['is_admin' => true]);
        $type = '<script>alert("unsafe")</script>';
        $id = (string) Str::uuid();
        DB::table('likes')->insert(['id' => $id, 'actor_id' => $admin->actor->id, 'likeable_type' => $type, 'likeable_id' => (string) Str::uuid()]);
        $this->actingAs($admin)->get(route('admin.database.index', ['tab' => 'sanity']))
            ->assertOk()->assertSee(__('openbook.admin.database.sanity_unknown_title'))->assertSee($type)->assertDontSee($type, false);
        $this->post(route('admin.database.sanity.run'))->assertRedirect();
        $this->assertDatabaseHas('likes', ['id' => $id]);
    }

    public static function nonAdminRoles(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_nonadmins_cannot_preview_or_clean(bool $moderator): void
    {
        $user = $this->createFullAccount('forbiddensanity', ['is_moderator' => $moderator]);
        $user->forceFill(['is_moderator' => $moderator])->save();
        $id = $this->orphanNotification($user->id);
        $this->actingAs($user)->get(route('admin.database.index', ['tab' => 'sanity']))->assertForbidden();
        $this->post(route('admin.database.sanity.run'))->assertForbidden();
        $this->assertDatabaseHas('notifications', ['id' => $id]);
    }

    public function test_guests_and_get_requests_cannot_execute_cleanup(): void
    {
        $this->post(route('admin.database.sanity.run'))->assertRedirect(route('login'));
        $admin = $this->createFullAccount('methodadmin', ['is_admin' => true]);
        $id = $this->orphanNotification($admin->id);
        $this->actingAs($admin)->get(route('admin.database.sanity.run'))->assertStatus(405);
        $this->assertDatabaseHas('notifications', ['id' => $id]);
    }

    public function test_error_rolls_back_notification_batch_and_releases_shared_lock(): void
    {
        $admin = $this->createFullAccount('failedweb', ['is_admin' => true]);
        $id = $this->orphanNotification($admin->id);
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'update "users"') && str_contains($query->sql, 'notifications_revision')) {
                throw new RuntimeException('Injected failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($admin)->post(route('admin.database.sanity.run'));
            $this->fail('Expected failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected failure', $exception->getMessage());
        }
        $this->assertDatabaseHas('notifications', ['id' => $id]);
        $this->assertDatabaseHas('push_notifications', ['notification_id' => $id]);
        $this->assertSame(0, $admin->fresh()->notifications_revision);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'database.sanity']);
        $lock = fopen(storage_path('framework/cache/database-sanity.lock'), 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        fclose($lock);
    }

    private function orphanNotification(string $recipient): string
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert(['id' => $id, 'recipient_id' => $recipient, 'type' => 'mention', 'notifiable_type' => 'post', 'notifiable_id' => (string) Str::uuid()]);
        DB::table('push_notifications')->insert(['id' => (string) Str::uuid(), 'notification_id' => $id]);

        return $id;
    }
}
