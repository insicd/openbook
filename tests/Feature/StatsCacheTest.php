<?php

namespace Tests\Feature;

use App\Application\Services\CachedStatsDataset;
use App\Application\Services\DomainBlockManager;
use App\Domain\Posts\Post;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class StatsCacheTest extends TestCase
{
    use CreatesAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'cache.default' => 'array']);
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->time('2026-10-22 12:37:00 UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_http_cache_is_shared_and_expiry_preserves_actual_generation_and_insertion_times(): void
    {
        $user = $this->createFullAccount('cacheviewer');
        $this->createPost($user->actor->id);
        $first = $this->actingAs($user)->getJson('/stats/ritmo')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
        $this->assertSame(300, $first['cache']['ttl_seconds']);
        $this->assertSame('2026-10-22T12:37:00+00:00', $first['cache']['cached_at']);
        $this->createPost($user->actor->id);
        $this->time('2026-10-22 12:40:00 UTC');
        $other = $this->createFullAccount('cacheother');
        $hit = $this->actingAs($other)->getJson('/stats/ritmo')->assertOk()->json();
        $this->assertSame($first, $hit);
        $this->time('2026-10-22 12:42:01 UTC');
        $fresh = $this->getJson('/stats/ritmo')->assertOk()->json();
        $this->assertSame(2, array_sum(array_column($fresh['rows'], 'total')));
        $this->assertNotSame($first['cache']['cached_at'], $fresh['cache']['cached_at']);
    }

    public function test_normal_edits_privacy_changes_and_suspension_wait_for_ttl(): void
    {
        $user = $this->createFullAccount('cacheprivacy');
        $post = $this->createPost($user->actor->id);
        $cache = app(CachedStatsDataset::class);
        $first = $cache->get('rhythm');
        $delivery = $cache->get('acquisition');
        $this->assertSame(1, array_sum(array_column($first['rows'], 'total')));
        $post->update(['body' => 'Edited', 'content_warning' => 'Warning']);
        Post::whereKey($post->id)->update(['visibility' => 'followers']);
        $user->actor->update(['remote_suspended' => true]);
        $this->assertSame($first, $cache->get('rhythm'));
        $this->time('2026-10-22 12:42:01 UTC');
        $this->assertSame(0, array_sum(array_column($cache->get('rhythm')['rows'], 'total')));
        $this->assertSame($delivery, $cache->get('acquisition'));
    }

    public function test_admin_clear_is_authorized_scoped_and_preserves_buckets(): void
    {
        $user = $this->createFullAccount('cacheadmin');
        $source = hash('sha256', 'cache-test-source');
        DB::table('stats_delivery_sources')->insert(['id' => $source, 'kind' => 'inbox']);
        DB::table('stats_content_delivery_buckets')->insert(['day' => '2026-10-22', 'source_id' => $source, 'count' => 7]);
        Cache::put('unrelated-cache', 'keep', 3600);
        $cache = app(CachedStatsDataset::class);
        $first = $cache->get('acquisition');
        $this->actingAs($user)->post(route('admin.database.stats-cache.clear'))->assertForbidden();
        $user->forceFill(['is_admin' => true])->save();
        $this->time('2026-10-22 12:38:00 UTC');
        $this->post(route('admin.database.stats-cache.clear'))->assertRedirect(route('admin.database.index', ['tab' => 'maintenance']));
        $this->assertSame('keep', Cache::get('unrelated-cache'));
        $this->assertDatabaseHas('stats_content_delivery_buckets', ['source_id' => $source, 'count' => 7]);
        $this->assertNotSame($first['cache']['cached_at'], $cache->get('acquisition')['cache']['cached_at']);
        $this->get(route('admin.database.index', ['tab' => 'maintenance']))->assertOk()->assertSee(__('stats.admin.clear'));
    }

    public function test_failed_cache_reads_fall_back_without_claiming_cache_insertion(): void
    {
        Cache::shouldReceive('get')->once()->andThrow(new \RuntimeException('Cache unavailable'));
        Cache::shouldReceive('remember')->once()->andThrow(new \RuntimeException('Cache unavailable'));
        $data = app(CachedStatsDataset::class)->get('rhythm');
        $this->assertNull($data['cache']['cached_at']);
        $this->assertNull($data['cache']['expires_at']);
        $this->assertCount(360, $data['rows']);
    }

    public function test_minimum_ttl_and_settings_changes_do_not_refresh_existing_data(): void
    {
        config(['stats.ttl.rhythm' => 1]);
        $cache = app(CachedStatsDataset::class);
        $first = $cache->get('rhythm');
        $this->assertSame(300, $first['cache']['ttl_seconds']);
        $this->time('2026-10-22 12:38:00 UTC');
        config(['openbook.moderation.hide_content_warnings_from_world' => true]);
        $this->assertSame($first, $cache->get('rhythm'));
        $this->time('2026-10-22 12:42:01 UTC');
        $this->assertNotSame($first['cache']['cached_at'], $cache->get('rhythm')['cache']['cached_at']);
    }

    public function test_clear_during_calculation_does_not_publish_into_the_current_generation(): void
    {
        $cache = app(CachedStatsDataset::class);
        $calculations = 0;
        DB::listen(function ($query) use ($cache, &$calculations): void {
            if (str_contains($query->sql, ' AS bucket')) {
                if (++$calculations === 1) {
                    $cache->clear();
                }
            }
        });
        $cache->get('rhythm');
        $this->assertSame(2, $calculations);
        $cache->get('rhythm');
        $this->assertSame(2, $calculations);
    }

    public function test_blocking_a_server_does_not_refresh_stats_before_expiry(): void
    {
        $user = $this->createFullAccount('cachedomain');
        $user->forceFill(['is_admin' => true])->save();
        $user->actor->update(['domain' => 'blocked.example', 'uri' => 'https://blocked.example/users/a']);
        $this->createPost($user->actor->id);
        $cache = app(CachedStatsDataset::class);
        $first = $cache->get('rhythm');
        $this->assertSame(1, array_sum(array_column($first['rows'], 'total')));
        app(DomainBlockManager::class)->block($user, 'blocked.example');
        $this->assertSame($first, $cache->get('rhythm'));
        $this->time('2026-10-22 12:42:01 UTC');
        $this->assertSame(0, array_sum(array_column($cache->get('rhythm')['rows'], 'total')));
    }

    public function test_hour_boundaries_do_not_short_circuit_the_ttl(): void
    {
        $this->time('2026-10-22 12:59:00 UTC');
        $cache = app(CachedStatsDataset::class);
        $first = $cache->get('rhythm');
        $this->time('2026-10-22 13:00:01 UTC');
        $this->assertSame($first, $cache->get('rhythm'));
        $this->time('2026-10-22 13:04:01 UTC');
        $this->assertSame('2026-10-22T13:00:00+00:00', $cache->get('rhythm')['period']['to']);
    }

    public function test_file_cache_works_and_rollback_cannot_leave_an_uncommitted_snapshot(): void
    {
        $path = sys_get_temp_dir().'/stats-cache-'.bin2hex(random_bytes(8));
        config(['cache.default' => 'file', 'cache.stores.file.path' => $path, 'cache.stores.file.lock_path' => $path]);
        try {
            $user = $this->createFullAccount('cachefile');
            $post = $this->createPost($user->actor->id);
            $cache = app(CachedStatsDataset::class);
            $first = $cache->get('rhythm');
            $this->assertNotNull($first['cache']['cached_at']);
            $this->assertSame($first, $cache->get('rhythm'));
            DB::beginTransaction();
            $post->update(['visibility' => 'followers']);
            $during = $cache->get('rhythm');
            $this->assertNull($during['cache']['cached_at']);
            DB::rollBack();
            $this->assertSame(1, array_sum(array_column($cache->get('rhythm')['rows'], 'total')));
        } finally {
            (new Filesystem)->deleteDirectory($path);
        }
    }

    public function test_query_errors_are_not_cached_and_a_later_request_can_recover(): void
    {
        $cache = app(CachedStatsDataset::class);
        Schema::rename('posts', 'posts_unavailable');
        $failures = 0;
        try {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                try {
                    $cache->get('rhythm');
                    $this->fail('The failed query must not become a cached response.');
                } catch (QueryException) {
                    $failures++;
                }
            }
        } finally {
            Schema::rename('posts_unavailable', 'posts');
        }
        $this->assertSame(2, $failures);
        $data = $cache->get('rhythm');
        $this->assertCount(360, $data['rows']);
        $this->assertNotNull($data['cache']['cached_at']);
        $this->assertSame($data, $cache->get('rhythm'));
    }

    private function time(string $at): void
    {
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    }

    private function createPost(string $actorId): Post
    {
        return Post::create(['actor_id' => $actorId, 'body' => 'Cache example', 'status' => 'published', 'visibility' => 'public', 'published_at' => '2026-10-22 11:00:00']);
    }
}
