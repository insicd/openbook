<?php

namespace Tests\Feature\Hashtags;

use App\Application\Queries\HashtagStatsQuery;
use App\Application\Queries\StatsQuery;
use App\Application\Services\EventComposer;
use App\Application\Services\HashtagStats;
use App\Application\Services\HashtagStatsBootstrap;
use App\Application\Services\InstanceSettings;
use App\Application\Services\LocalContentDeleter;
use App\Application\Services\PostComposer;
use App\Domain\Posts\Hashtag;
use App\Domain\Posts\Post;
use App\Federation\Actors\RemoteActorDeletionService;
use App\Federation\Inbox\InboxActivityProcessor;
use App\Federation\Inbox\InboxItem;
use App\Federation\Inbox\RemoteEventDeletionService;
use App\Federation\Inbox\RemoteEventIngester;
use App\Federation\Inbox\RemoteNoteUpserter;
use App\Infrastructure\Database\SystemSetting;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class HashtagStatsTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors;

    private string $databaseFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-08T12:00:00Z'));
        config(['app.timezone' => date_default_timezone_get()]);
        $this->databaseFile = tempnam(sys_get_temp_dir(), 'openbook-hashtag-stats-');
        config(['database.default' => 'hashtag_test', 'database.connections.hashtag_test' => [
            'driver' => 'sqlite', 'database' => $this->databaseFile, 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        Artisan::call('migrate', ['--database' => 'hashtag_test', '--force' => true]);
        Queue::fake();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        app(HashtagStats::class)->connection()->disconnect();
        DB::purge('hashtag_test');
        parent::tearDown();
        @unlink($this->databaseFile);
    }

    private function bucketCount(string $tag, string $period = 'daily', ?string $date = null): int
    {
        $column = $period === 'daily' ? 'day' : 'month';
        $date ??= $period === 'daily' ? '2026-11-08' : '2026-11-01';

        return (int) DB::table('stats_hashtag_'.$period.'_buckets as b')->join('hashtags as h', 'h.id', '=', 'b.hashtag_id')
            ->where('h.name', $tag)->where('b.'.$column, $date)->sum('count');
    }

    public function test_local_create_edit_visibility_and_delete_update_both_buckets_once(): void
    {
        $owner = $this->createFullAccount('bucketowner');
        $composer = app(PostComposer::class);
        $post = $composer->compose($owner->actor, ['body' => '#music #music #guitar', 'visibility' => 'public']);
        $this->assertSame(1, $this->bucketCount('music'));
        $this->assertSame(1, $this->bucketCount('music', 'monthly'));
        $composer->update($owner->actor, $post, ['body' => '#guitar #rock']);
        $this->assertSame(0, $this->bucketCount('music'));
        $this->assertSame(1, $this->bucketCount('guitar'));
        $this->assertSame(1, $this->bucketCount('rock', 'monthly'));
        $post->refresh();
        $composer->update($owner->actor, $post, ['body' => '#guitar #rock', 'visibility' => 'followers']);
        $this->assertSame(0, $this->bucketCount('guitar'));
        $composer->update($owner->actor, $post->fresh(), ['body' => '#guitar #rock', 'visibility' => 'public']);
        $this->assertSame(1, $this->bucketCount('guitar'));
        app(LocalContentDeleter::class)->delete($owner->actor, $post->fresh());
        app(LocalContentDeleter::class)->delete($owner->actor, $post->fresh());
        $this->assertSame(0, $this->bucketCount('guitar'));
        $this->assertSame(0, $this->bucketCount('rock', 'monthly'));
    }

    public function test_metrics_wait_for_commit_and_disappear_on_rollback(): void
    {
        $owner = $this->createFullAccount('buckettransaction');
        DB::beginTransaction();
        app(PostComposer::class)->compose($owner->actor, ['body' => '#committed', 'visibility' => 'public']);
        $this->assertSame(0, $this->bucketCount('committed'));
        DB::commit();
        $this->assertSame(1, $this->bucketCount('committed'));
        DB::beginTransaction();
        app(PostComposer::class)->compose($owner->actor, ['body' => '#rolledback', 'visibility' => 'public']);
        DB::rollBack();
        $this->assertSame(0, $this->bucketCount('rolledback'));
    }

    public function test_failed_metrics_do_not_fail_content_or_leave_half_a_contribution(): void
    {
        $owner = $this->createFullAccount('bucketfailure');
        Schema::drop('stats_hashtag_monthly_buckets');
        $post = app(PostComposer::class)->compose($owner->actor, ['body' => '#survives', 'visibility' => 'public']);
        $this->assertTrue($post->fresh()->isPublished());
        $this->assertSame(0, $this->bucketCount('survives'));
    }

    public function test_current_retention_controls_late_imports_without_a_watermark(): void
    {
        $remote = $this->createRemoteActor('bucketremote');
        SystemSetting::put(InstanceSettings::KEY_REMOTE_POST_PERTINENT_RETENTION_DAYS, '30');
        SystemSetting::put(InstanceSettings::KEY_REMOTE_POST_NON_PERTINENT_RETENTION_DAYS, '7');
        $published = now()->subDays(6);
        $note = ['id' => $remote->uri.'/post', 'type' => 'Note', 'attributedTo' => $remote->uri,
            'to' => ['https://www.w3.org/ns/activitystreams#Public'], 'published' => $published->toAtomString(),
            'tag' => [['type' => 'Hashtag', 'name' => '#music']]];
        $ingester = app(RemoteNoteUpserter::class);
        $post = $ingester->upsertPost($note, $note['id'], $remote, '#music', $published);
        $this->assertSame(1, $this->bucketCount('music', 'monthly'));
        $ingester->upsertPost($note, $note['id'], $remote, '#music', $published);
        $this->assertSame(1, $this->bucketCount('music', 'monthly'));
        $this->travel(2)->days();
        // Retention is a physical delete, deliberately not a federated Delete.
        DB::table('posts')->where('id', $post->id)->delete();
        $this->assertSame(1, $this->bucketCount('music', 'monthly'));
        $ingester->upsertPost($note, $note['id'], $remote, '#music', $published);
        $this->assertSame(1, $this->bucketCount('music', 'monthly'));
        // Changing settings can reopen the window: this approximation is intentional.
        SystemSetting::put(InstanceSettings::KEY_REMOTE_POST_NON_PERTINENT_RETENTION_DAYS, '30');
        $old = Post::where('uri', $note['id'])->first();
        DB::table('posts')->where('id', $old->id)->delete();
        $ingester->upsertPost($note, $note['id'], $remote, '#music', $published);
        $this->assertSame(2, $this->bucketCount('music', 'monthly'));
    }

    public function test_remote_update_and_repeated_authenticated_delete_do_not_double_count(): void
    {
        $remote = $this->createRemoteActor('bucketinbox');
        $published = now()->subHour();
        $uri = $remote->uri.'/post';
        $note = ['id' => $uri, 'type' => 'Note', 'attributedTo' => $remote->uri,
            'to' => ['https://www.w3.org/ns/activitystreams#Public'], 'published' => $published->toAtomString(),
            'tag' => [['type' => 'Hashtag', 'name' => '#old']]];
        app(RemoteNoteUpserter::class)->upsertPost($note, $uri, $remote, '#old', $published);
        $note['tag'] = [['type' => 'Hashtag', 'name' => '#new']];
        $note['updated'] = now()->toAtomString();
        app(RemoteNoteUpserter::class)->upsertPost($note, $uri, $remote, '#new', $published);
        $this->assertSame(0, $this->bucketCount('old'));
        $this->assertSame(1, $this->bucketCount('new'));
        for ($i = 0; $i < 2; $i++) {
            $payload = ['id' => $uri.'/delete/'.$i, 'type' => 'Delete', 'actor' => $remote->uri, 'object' => $uri];
            $item = InboxItem::create(['is_shared' => true, 'remote_activity_uri' => $payload['id'],
                'activity_type' => 'Delete', 'actor_uri' => $remote->uri, 'payload' => json_encode($payload),
                'signature_valid' => true, 'status' => 'pending', 'received_at' => now()]);
            app(InboxActivityProcessor::class)->process($item);
        }
        $this->assertSame(0, $this->bucketCount('new'));
        $this->assertSame(0, $this->bucketCount('new', 'monthly'));
    }

    public function test_bootstrap_is_repeatable_ignores_mutable_cutoff_and_history_survives_cleanup(): void
    {
        $owner = $this->createFullAccount('buckethistory');
        SystemSetting::put(InstanceSettings::KEY_REMOTE_POST_NON_PERTINENT_RETENTION_DAYS, '7');
        $tag = Hashtag::create(['name' => 'history']);
        $post = Post::create(['actor_id' => $owner->actor->id, 'body' => '#history', 'visibility' => 'public', 'published_at' => '2026-08-03 12:00:00']);
        $post->hashtags()->attach($tag);
        $private = Post::create(['actor_id' => $owner->actor->id, 'body' => '#history', 'visibility' => 'followers', 'published_at' => '2026-08-03 12:00:00']);
        $private->hashtags()->attach($tag);
        $bootstrap = app(HashtagStatsBootstrap::class);
        $bootstrap->reset();
        $this->assertTrue($bootstrap->month(CarbonImmutable::parse('2026-08-01', 'UTC')));
        $this->assertFalse($bootstrap->month(CarbonImmutable::parse('2026-08-01', 'UTC')));
        $this->assertSame(1, $this->bucketCount('history', 'monthly', '2026-08-01'));
        DB::table('posts')->whereIn('id', [$post->id, $private->id])->delete();
        $this->assertSame(1, $this->bucketCount('history', 'monthly', '2026-08-01'));
        $data = app(StatsQuery::class)->hashtags(CarbonImmutable::parse('2024-12-01', 'UTC'), CarbonImmutable::now('UTC'));
        $this->assertSame('history', $data->first()->name);
        $this->assertSame(1, (int) $data->first()->count);
    }

    public function test_graph_reads_zero_filled_daily_buckets_and_keeps_public_page_access(): void
    {
        $owner = $this->createFullAccount('bucketgraph');
        app(PostComposer::class)->compose($owner->actor, ['body' => '#music', 'visibility' => 'public']);
        $response = $this->get('/tag/music')->assertOk()->assertViewHas('tagTrend', fn ($rows) => count($rows) === 30
            && $rows[0]['day'] === '2026-10-10' && $rows[0]['count'] === 0 && $rows[29]['count'] === 1);
        $response->assertSee('hashtag-trend.js')->assertSee('chart.umd.min.js')->assertSee('data-hashtag-trend', false);
        $this->get('/tag/notknown')->assertOk()->assertDontSee('hashtag-trend.js');
    }

    public function test_prune_keeps_exact_day_and_month_boundaries_and_old_posts_only_affect_months(): void
    {
        $owner = $this->createFullAccount('bucketbounds');
        $tag = Hashtag::create(['name' => 'boundary']);
        foreach (['2026-10-09', '2026-10-10'] as $day) {
            DB::table('stats_hashtag_daily_buckets')->insert(['hashtag_id' => $tag->id, 'day' => $day, 'count' => 2]);
        }
        foreach (['2024-11-01', '2024-12-01'] as $month) {
            DB::table('stats_hashtag_monthly_buckets')->insert(['hashtag_id' => $tag->id, 'month' => $month, 'count' => 2]);
        }
        $this->assertSame(2, app(HashtagStats::class)->prune());
        $this->assertSame(2, $this->bucketCount('boundary', 'daily', '2026-10-10'));
        $this->assertSame(2, $this->bucketCount('boundary', 'monthly', '2024-12-01'));
        $post = Post::create(['actor_id' => $owner->actor->id, 'body' => '#boundary', 'visibility' => 'public', 'published_at' => now()->subDays(31)]);
        $post->hashtags()->attach($tag);
        app(HashtagStats::class)->changed([], $post);
        $this->assertSame(0, $this->bucketCount('boundary', 'daily', '2026-10-08'));
        $this->assertSame(1, $this->bucketCount('boundary', 'monthly', '2026-10-01'));
    }

    public function test_migration_seeds_existing_history_and_actor_delete_corrects_recent_contributions(): void
    {
        $migration = require database_path('migrations/2026_11_08_000000_create_hashtag_statistic_buckets.php');
        $migration->down();
        $remote = $this->createRemoteActor('migrationhistory');
        $tag = Hashtag::create(['name' => 'migrated']);
        foreach (['2026-08-03 10:00:00', '2026-11-08 10:00:00'] as $published) {
            $post = Post::create(['actor_id' => $remote->id, 'uri' => $remote->uri.'/'.substr($published, 0, 10),
                'body' => '#migrated', 'visibility' => 'public', 'published_at' => $published]);
            $post->hashtags()->attach($tag);
        }
        $migration->up();
        $this->assertSame(1, $this->bucketCount('migrated'));
        $this->assertSame(1, $this->bucketCount('migrated', 'monthly', '2026-08-01'));
        SystemSetting::put(InstanceSettings::KEY_REMOTE_POST_NON_PERTINENT_RETENTION_DAYS, '7');
        app(RemoteActorDeletionService::class)->delete($remote);
        $this->assertSame(0, $this->bucketCount('migrated'));
        $this->assertSame(1, $this->bucketCount('migrated', 'monthly', '2026-08-01'));
    }

    public function test_bootstrap_uses_utc_month_and_day_for_sqlite_local_wall_clock(): void
    {
        config(['app.timezone' => 'Europe/Helsinki']);
        $owner = $this->createFullAccount('buckettz');
        $tag = Hashtag::create(['name' => 'tzboundary']);
        foreach (['2026-11-01 00:30:00', '2026-11-09 00:30:00'] as $published) {
            $post = Post::create(['actor_id' => $owner->actor->id, 'body' => '#tzboundary',
                'visibility' => 'public', 'published_at' => $published]);
            $post->hashtags()->attach($tag);
        }
        $bootstrap = app(HashtagStatsBootstrap::class);
        $bootstrap->reset();
        $bootstrap->month(CarbonImmutable::parse('2026-10-01', 'UTC'));
        $bootstrap->month(CarbonImmutable::parse('2026-11-01', 'UTC'));
        $this->assertSame(1, $this->bucketCount('tzboundary', 'daily', '2026-10-31'));
        $this->assertSame(1, $this->bucketCount('tzboundary', 'monthly', '2026-10-01'));
        $this->assertSame(0, $this->bucketCount('tzboundary', 'monthly'));
    }

    public function test_database_admin_lists_both_tables_and_cleans_only_expired_buckets(): void
    {
        $admin = $this->createFullAccount('bucketdatabase');
        $admin->forceFill(['is_admin' => true])->save();
        $tag = Hashtag::create(['name' => 'maintenance']);
        foreach (['2026-10-09', '2026-11-08'] as $day) {
            DB::table('stats_hashtag_daily_buckets')->insert(['hashtag_id' => $tag->id, 'day' => $day, 'count' => 3]);
        }
        foreach (['2024-11-01', '2026-11-01'] as $month) {
            DB::table('stats_hashtag_monthly_buckets')->insert(['hashtag_id' => $tag->id, 'month' => $month, 'count' => 3]);
        }
        $this->actingAs($admin)->get(route('admin.database.index', ['tab' => 'maintenance']))->assertOk()
            ->assertSee(__('openbook.admin.database.tables.hashtag_daily'))
            ->assertSee(__('openbook.admin.database.tables.hashtag_monthly'));
        foreach (['daily', 'monthly'] as $period) {
            $this->post(route('admin.database.purge'), ['table' => 'stats_hashtag_'.$period.'_buckets'])->assertRedirect();
        }
        $this->assertSame(3, $this->bucketCount('maintenance'));
        $this->assertSame(3, $this->bucketCount('maintenance', 'monthly'));
        $this->assertSame(0, $this->bucketCount('maintenance', 'daily', '2026-10-09'));
        $this->assertSame(0, $this->bucketCount('maintenance', 'monthly', '2024-11-01'));
    }

    public function test_bootstrap_reset_drops_previous_history_and_cannot_run_accidentally_on_a_live_site(): void
    {
        $this->artisan('openbook:backfill-hashtag-stats', ['--reset' => true])->assertFailed();
        $owner = $this->createFullAccount('resetbucket');
        $post = app(PostComposer::class)->compose($owner->actor, ['body' => '#reset', 'visibility' => 'public']);
        $this->assertSame(1, $this->bucketCount('reset'));
        DB::table('posts')->where('id', $post->id)->delete();
        app(HashtagStatsBootstrap::class)->reset();
        $this->assertSame(0, $this->bucketCount('reset'));
        $this->assertTrue(app(HashtagStatsBootstrap::class)->month(CarbonImmutable::now('UTC')));
        $this->assertSame(0, $this->bucketCount('reset', 'monthly'));
    }

    public function test_explicit_reset_command_rebuilds_once_and_resumes_without_real_maintenance_files(): void
    {
        $owner = $this->createFullAccount('bucketcommand');
        app(PostComposer::class)->compose($owner->actor, ['body' => '#command', 'visibility' => 'public']);
        $this->mock(MaintenanceMode::class)->shouldReceive('active')->andReturn(true);
        $this->artisan('openbook:backfill-hashtag-stats', ['--reset' => true])->assertSuccessful();
        $this->assertSame(1, $this->bucketCount('command'));
        $this->assertSame(1, $this->bucketCount('command', 'monthly'));
        $this->artisan('openbook:backfill-hashtag-stats', ['--month' => '2026-11'])->assertSuccessful();
        $this->assertSame(1, $this->bucketCount('command'));
        $this->artisan('openbook:backfill-hashtag-stats', ['--month' => 'invalid'])->assertFailed();
        $this->artisan('openbook:backfill-hashtag-stats', ['--month' => '2026-11', '--reset' => true])->assertFailed();
        $lock = Cache::lock('hashtag-stats:rebuild', 7200);
        $this->assertTrue($lock->get());
        try {
            $this->artisan('openbook:backfill-hashtag-stats', ['--reset' => true])->assertFailed();
        } finally {
            $lock->release();
        }
        $this->assertSame(1, $this->bucketCount('command'));
    }

    private function eventData(array $overrides = []): array
    {
        return array_replace(['name' => 'Synthetic concert', 'content' => '#concert #concert',
            'start_at' => '2026-12-20T18:00', 'timezone' => 'UTC', 'mode' => 'online',
            'participation_url' => 'https://events.example/live', 'join_mode' => 'free',
            'visibility' => 'public'], $overrides);
    }

    public function test_local_events_share_post_buckets_and_edit_cancel_delete_only_once(): void
    {
        $owner = $this->createFullAccount('eventbuckets');
        app(PostComposer::class)->compose($owner->actor, ['body' => '#concert', 'visibility' => 'public']);
        $composer = app(EventComposer::class);
        $event = $composer->compose($owner->actor, $this->eventData());
        $this->assertSame(2, $this->bucketCount('concert'));
        $this->assertSame(2, $this->bucketCount('concert', 'monthly'));
        $event = $composer->update($owner->actor, $event, $this->eventData(['content' => '#music']));
        $this->assertSame(1, $this->bucketCount('concert'));
        $this->assertSame(1, $this->bucketCount('music'));
        $event = $composer->update($owner->actor, $event, $this->eventData(['content' => '#music', 'visibility' => 'unlisted']));
        $this->assertSame(0, $this->bucketCount('music'));
        $event = $composer->update($owner->actor, $event, $this->eventData(['content' => '#music']));
        $composer->cancel($owner->actor, $event);
        $composer->cancel($owner->actor, $event->fresh());
        $composer->delete($owner->actor, $event->fresh());
        $this->assertSame(0, $this->bucketCount('music', 'monthly'));
        $event = $composer->compose($owner->actor, $this->eventData());
        $composer->delete($owner->actor, $event);
        $composer->delete($owner->actor, $event->fresh());
        $this->assertSame(1, $this->bucketCount('concert'));
    }

    public function test_remote_event_redelivery_update_and_actor_deletion_correct_contributions(): void
    {
        $remote = $this->createRemoteActor('eventbucketremote', 'events.example');
        $document = ['id' => $remote->uri.'/concert', 'type' => 'Event', 'actor' => $remote->uri,
            'attributedTo' => $remote->uri, 'name' => 'Synthetic concert', 'startTime' => '2026-12-20T18:00:00Z',
            'published' => now()->subHour()->toAtomString(), 'updated' => now()->subHour()->toAtomString(),
            'to' => ['https://www.w3.org/ns/activitystreams#Public'], 'tag' => [['type' => 'Hashtag', 'name' => '#concert']]];
        $ingester = app(RemoteEventIngester::class);
        $event = $ingester->ingest($document, $remote, 'Create');
        $this->assertNotNull($event);
        $ingester->ingest($document, $remote, 'Announce');
        $this->assertSame(1, $this->bucketCount('concert'));
        $document['tag'] = [['type' => 'Hashtag', 'name' => '#music']];
        $document['updated'] = now()->toAtomString();
        $event = $ingester->ingest($document, $remote, 'Update');
        $this->assertSame(0, $this->bucketCount('concert'));
        $this->assertSame(1, $this->bucketCount('music'));
        $document['eventStatus'] = 'CANCELLED';
        $ingester->ingest($document, $remote, 'Update');
        $this->assertSame(0, $this->bucketCount('music'));
        $document['eventStatus'] = 'TENTATIVE';
        $ingester->ingest($document, $remote, 'Update');
        $this->assertSame(1, $this->bucketCount('music'));
        app(RemoteActorDeletionService::class)->delete($remote);
        $this->assertSame(0, $this->bucketCount('music'));
        app(RemoteEventDeletionService::class)->delete($event->fresh());
        $this->assertSame(0, $this->bucketCount('music', 'monthly'));
    }

    public function test_reset_rebuilds_public_posts_and_events_and_preserves_publication_periods(): void
    {
        $owner = $this->createFullAccount('mixedbootstrap');
        app(PostComposer::class)->compose($owner->actor, ['body' => '#concert', 'visibility' => 'public']);
        app(EventComposer::class)->compose($owner->actor, $this->eventData());
        app(EventComposer::class)->compose($owner->actor, $this->eventData(['visibility' => 'followers']));
        $this->mock(MaintenanceMode::class)->shouldReceive('active')->andReturn(true);
        $this->artisan('openbook:backfill-hashtag-stats', ['--reset' => true])->assertSuccessful();
        $this->assertSame(2, $this->bucketCount('concert'));
        $this->assertSame(2, $this->bucketCount('concert', 'monthly'));
        $this->assertSame(0, $this->bucketCount('concert', 'monthly', '2026-12-01'));
        $this->artisan('openbook:backfill-hashtag-stats')->assertSuccessful();
        $this->assertSame(2, $this->bucketCount('concert'));
        DB::table('events')->delete();
        $this->assertSame(2, $this->bucketCount('concert'));
    }

    public function test_batch_tickers_use_one_query_and_zero_fill_seven_days_for_all_tags(): void
    {
        $tags = [];
        for ($i = 0; $i < 100; $i++) {
            $tag = Hashtag::create(['name' => 'tickertopic'.$i]);
            $tags[] = $tag->id;
            DB::table('stats_hashtag_daily_buckets')->insert(['hashtag_id' => $tag->id, 'day' => '2026-11-08', 'count' => $i]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $series = app(HashtagStatsQuery::class)->dailyForTags($tags);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(1, $queries);
        $this->assertCount(100, $series);
        $this->assertCount(7, $series[$tags[99]]);
        $this->assertSame(['day' => '2026-11-02', 'count' => 0], $series[$tags[99]][0]);
        $this->assertSame(['day' => '2026-11-08', 'count' => 99], $series[$tags[99]][6]);
    }

    public function test_events_respect_freeze_moderation_and_transaction_rollback(): void
    {
        $owner = $this->createFullAccount('eventpolicy');
        $composer = app(EventComposer::class);
        DB::beginTransaction();
        $composer->compose($owner->actor, $this->eventData());
        DB::rollBack();
        $this->assertSame(0, $this->bucketCount('concert'));
        config(['openbook.moderation.hide_content_warnings_from_world' => true]);
        $composer->compose($owner->actor, $this->eventData(['sensitive' => true]));
        $this->assertSame(0, $this->bucketCount('concert'));
        config(['openbook.moderation.hide_content_warnings_from_world' => false]);
        $event = $composer->compose($owner->actor, $this->eventData());
        SystemSetting::put(InstanceSettings::KEY_REMOTE_POST_NON_PERTINENT_RETENTION_DAYS, '7');
        $this->travel(8)->days();
        $composer->delete($owner->actor, $event);
        $this->assertSame(1, $this->bucketCount('concert', 'monthly'));
    }

    public function test_trending_page_renders_all_tickers_with_one_bucket_query(): void
    {
        $owner = $this->createFullAccount('trendpagerows');
        app(EventComposer::class)->compose($owner->actor, $this->eventData(['content' => '#concert #music']));
        $this->actingAs($owner);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = $this->get('/tendenze')->assertOk()->assertViewHas('tagTrends', fn ($series) => count($series) === 2);
        $queries = array_filter(DB::getQueryLog(), fn ($query) => str_contains($query['query'], 'stats_hashtag_daily_buckets'));
        DB::disableQueryLog();
        $this->assertCount(1, $queries);
        $response->assertSee('ob-hashtag-sparkline', false)->assertSee(__('openbook.hashtags.ticker_note'));
    }
}
