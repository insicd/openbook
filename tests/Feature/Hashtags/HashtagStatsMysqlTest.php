<?php

namespace Tests\Feature\Hashtags;

use App\Application\Queries\HashtagStatsQuery;
use App\Application\Queries\StatsQuery;
use App\Application\Services\HashtagStats;
use App\Application\Services\HashtagStatsBootstrap;
use App\Domain\Posts\Hashtag;
use App\Domain\Posts\Post;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class HashtagStatsMysqlTest extends TestCase
{
    use CreatesAccounts;

    public function test_schema_counters_readers_bootstrap_and_pruning_on_isolated_mysql(): void
    {
        if (! filter_var(env('OPENBOOK_TEST_HASHTAG_STATS_MYSQL', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('Set OPENBOOK_TEST_HASHTAG_STATS_MYSQL=1 for isolated schema and query-plan checks.');
        }
        $this->travelTo(CarbonImmutable::parse('2026-11-08T12:00:00Z'));
        $config = array_replace(config('database.connections.mysql'), ['url' => null, 'database' => null, 'prefix' => '',
            'host' => env('OPENBOOK_TEST_MYSQL_HOST', '127.0.0.1'), 'port' => env('OPENBOOK_TEST_MYSQL_PORT', '3307'),
            'unix_socket' => env('OPENBOOK_TEST_MYSQL_SOCKET', ''), 'username' => env('OPENBOOK_TEST_MYSQL_USERNAME', 'root'),
            'password' => env('OPENBOOK_TEST_MYSQL_PASSWORD', '')]);
        config(['database.connections.hashtag_admin' => $config]);
        $schema = 'openbook_hashtag_stats_'.Str::lower(Str::random(12));
        DB::connection('hashtag_admin')->statement('CREATE DATABASE `'.$schema.'`');
        try {
            config(['database.connections.hashtag_mysql' => array_replace($config, ['database' => $schema]), 'database.default' => 'hashtag_mysql']);
            Artisan::call('migrate', ['--database' => 'hashtag_mysql', '--force' => true]);
            Queue::fake();
            $owner = $this->createFullAccount('mysqlhashtagstats');
            $now = CarbonImmutable::now('UTC');
            $tags = $monthly = $daily = [];
            for ($i = 0; $i < 2000; $i++) {
                $id = (string) Str::uuid();
                $tags[] = ['id' => $id, 'name' => 'topic'.$i];
                for ($offset = 0; $offset < 36; $offset++) {
                    $monthly[] = ['hashtag_id' => $id, 'month' => $now->startOfMonth()->subMonthsNoOverflow($offset)->toDateString(), 'count' => ($i % 20) + 1];
                }
                for ($offset = 0; $offset < 60; $offset++) {
                    $daily[] = ['hashtag_id' => $id, 'day' => $now->subDays($offset)->toDateString(), 'count' => 3];
                }
            }
            DB::table('hashtags')->insert($tags);
            foreach (array_chunk($monthly, 1000) as $chunk) {
                DB::table('stats_hashtag_monthly_buckets')->insert($chunk);
            }
            foreach (array_chunk($daily, 1000) as $chunk) {
                DB::table('stats_hashtag_daily_buckets')->insert($chunk);
            }
            DB::select('ANALYZE TABLE hashtags, stats_hashtag_daily_buckets, stats_hashtag_monthly_buckets');
            $queries = [];
            DB::listen(function (QueryExecuted $query) use (&$queries): void {
                if (str_starts_with($query->sql, 'select') && (str_contains($query->sql, 'stats_hashtag_') || str_contains($query->sql, 'post_hashtags'))) {
                    $queries[] = [$query->sql, $query->bindings];
                }
            });
            $series = app(HashtagStatsQuery::class)->daily($tags[0]['id']);
            $this->assertCount(30, $series);
            $this->assertSame(3, $series[0]['count']);
            $batch = app(HashtagStatsQuery::class)->dailyForTags(array_column(array_slice($tags, 0, 100), 'id'));
            $this->assertCount(100, $batch);
            $this->assertCount(7, $batch[$tags[0]['id']]);
            $ranked = app(StatsQuery::class)->hashtags($now->startOfMonth()->subMonthsNoOverflow(23), $now);
            $this->assertNotEmpty($ranked);
            $indexes = [];
            foreach ($queries as [$sql, $bindings]) {
                foreach (DB::select('EXPLAIN FORMAT=TRADITIONAL '.$sql, $bindings) as $row) {
                    if (in_array($row->table, ['stats_hashtag_daily_buckets', 'b'], true)) {
                        $this->assertNotNull($row->key, $sql);
                        $indexes[] = $row->key;
                    }
                }
            }
            $this->assertNotEmpty($indexes);
            $dailyCutoff = $now->subDays(29)->toDateString();
            $monthlyCutoff = $now->startOfMonth()->subMonthsNoOverflow(23)->toDateString();
            foreach ([['stats_hashtag_daily_buckets', 'day', $dailyCutoff], ['stats_hashtag_monthly_buckets', 'month', $monthlyCutoff]] as [$table, $column, $cutoff]) {
                $plan = DB::select('EXPLAIN FORMAT=TRADITIONAL DELETE FROM `'.$table.'` WHERE `'.$column.'` < ? ORDER BY `'.$column.'` LIMIT 1000', [$cutoff]);
                $this->assertNotEmpty($plan);
                fwrite(STDERR, "\nPrune {$table}: ".json_encode($plan)."\n");
            }
            $this->assertGreaterThan(0, app(HashtagStats::class)->prune());
            $this->assertSame(0, DB::table('stats_hashtag_daily_buckets')->where('day', '<', $dailyCutoff)->count());
            $tag = Hashtag::create(['name' => 'bootstrapmysql']);
            $post = Post::create(['actor_id' => $owner->actor->id, 'body' => '#bootstrapmysql',
                'visibility' => 'public', 'published_at' => now()->subHour()]);
            $post->hashtags()->attach($tag->id);
            $posts = $links = $events = $eventLinks = [];
            for ($i = 0; $i < 5000; $i++) {
                $id = (string) Str::uuid();
                $posts[] = ['id' => $id, 'actor_id' => $owner->actor->id, 'body' => 'Synthetic post',
                    'visibility' => 'public', 'status' => 'published', 'published_at' => $now->subDays($i % 730)->format('Y-m-d H:i:s')];
                $links[] = ['post_id' => $id, 'hashtag_id' => $tags[$i % 2000]['id']];
                $events[] = ['id' => $id, 'actor_id' => $owner->actor->id, 'uri' => 'https://events.example/synthetic/'.$id,
                    'name' => 'Synthetic event', 'start_at' => $now->addMonth()->format('Y-m-d H:i:s'),
                    'visibility' => 'public', 'status' => 'scheduled', 'published_at' => $posts[array_key_last($posts)]['published_at']];
                $eventLinks[] = ['event_id' => $id, 'hashtag_id' => $tags[($i + 1) % 2000]['id']];
            }
            foreach (array_chunk($posts, 500) as $chunk) {
                DB::table('posts')->insert($chunk);
            }
            foreach (array_chunk($links, 1000) as $chunk) {
                DB::table('post_hashtags')->insert($chunk);
            }
            foreach (array_chunk($events, 500) as $chunk) {
                DB::table('events')->insert($chunk);
            }
            foreach (array_chunk($eventLinks, 1000) as $chunk) {
                DB::table('event_hashtags')->insert($chunk);
            }
            DB::select('ANALYZE TABLE posts, post_hashtags, events, event_hashtags');
            $bootstrapPlans = [];
            DB::listen(function (QueryExecuted $query) use (&$bootstrapPlans): void {
                if (str_starts_with($query->sql, 'insert into `stats_hashtag_') && preg_match('/\)\s+(select\s.*)/is', $query->sql, $matches)) {
                    $bootstrapPlans[] = DB::select('EXPLAIN FORMAT=TRADITIONAL '.$matches[1], $query->bindings);
                }
            });
            app(HashtagStatsBootstrap::class)->reset();
            $this->assertTrue(app(HashtagStatsBootstrap::class)->month($now->startOfMonth()));
            $this->assertFalse(app(HashtagStatsBootstrap::class)->month($now->startOfMonth()));
            $this->assertCount(2, $bootstrapPlans);
            foreach ($bootstrapPlans as $plan) {
                fwrite(STDERR, "\nBootstrap plan: ".json_encode($plan)."\n");
                foreach ($plan as $row) {
                    if (in_array($row->table, ['p', 'e', 'tags'], true)) {
                        $this->assertNotNull($row->key);
                    }
                }
            }

            $this->assertSame(1, (int) DB::table('stats_hashtag_monthly_buckets')->where('hashtag_id', $tag->id)->value('count'));
            $before = app(HashtagStats::class)->snapshot($post);
            $post->update(['status' => 'deleted']);
            app(HashtagStats::class)->changed($before, null);
            $this->assertSame(0, (int) DB::table('stats_hashtag_monthly_buckets')->where('hashtag_id', $tag->id)->value('count'));
            fwrite(STDERR, "\nHashtag reader indexes: ".implode(', ', array_unique($indexes))."\n");
            $migration = require database_path('migrations/2026_11_08_000000_create_hashtag_statistic_buckets.php');
            $migration->down();
            $this->assertFalse(Schema::hasTable('stats_hashtag_daily_buckets'));
            $migration->up();
            $this->assertSame(1, (int) DB::table('stats_hashtag_daily_buckets')->where('hashtag_id', $tags[0]['id'])->where('day', $now->toDateString())->value('count'));
            $this->assertSame(2, (int) DB::table('stats_hashtag_monthly_buckets')->where('hashtag_id', $tags[1]['id'])->where('month', $now->startOfMonth()->toDateString())->value('count'));

        } finally {
            app(HashtagStats::class)->connection()->disconnect();
            DB::purge('hashtag_mysql');
            config(['database.default' => 'sqlite']);
            DB::connection('hashtag_admin')->statement('DROP DATABASE `'.$schema.'`');
            DB::purge('hashtag_admin');
        }
    }
}
