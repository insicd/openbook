<?php

namespace Tests\Feature\Moderation;

use App\Application\Queries\ReportModerationQuery;
use App\Domain\Moderation\Report;
use App\Federation\Resolution\ObjectResolver;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\TestsFederatedReportPersistence;
use Tests\TestCase;

class FederatedReportMysqlPersistenceTest extends TestCase
{
    use TestsFederatedReportPersistence;

    private ?string $temporarySchema = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (! filter_var(env('OPENBOOK_TEST_REPORTS_MYSQL', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('Set OPENBOOK_TEST_REPORTS_MYSQL=1 to run isolated MySQL report tests.');
        }
        $connection = array_replace(config('database.connections.mysql'), [
            'url' => null, 'database' => null, 'prefix' => '',
            'host' => env('OPENBOOK_TEST_MYSQL_HOST', '127.0.0.1'),
            'port' => env('OPENBOOK_TEST_MYSQL_PORT', '3307'),
            'unix_socket' => env('OPENBOOK_TEST_MYSQL_SOCKET', ''),
            'username' => env('OPENBOOK_TEST_MYSQL_USERNAME', 'root'),
            'password' => env('OPENBOOK_TEST_MYSQL_PASSWORD', ''),
        ]);
        config(['database.connections.report_admin' => $connection]);
        $schema = 'openbook_reports_test_'.Str::lower(Str::random(16));
        DB::connection('report_admin')->statement('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->temporarySchema = $schema;
        config(['database.connections.report_schema' => array_replace($connection, ['database' => $schema]),
            'database.default' => 'report_schema']);
        Artisan::call('migrate', ['--database' => 'report_schema', '--force' => true]);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->temporarySchema !== null) {
                DB::purge('report_schema');
                DB::connection('report_admin')->statement('DROP DATABASE `'.$this->temporarySchema.'`');
                $this->temporarySchema = null;
            }
        } finally {
            DB::purge('report_admin');
            config(['database.default' => 'sqlite']);
            parent::tearDown();
        }
    }

    public function test_two_concurrent_deliveries_create_one_complete_case(): void
    {
        [$sender, $targets] = $this->reportFixture();
        $children = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $process = proc_open([PHP_BINARY, base_path('tests/Support/ConcurrentFederatedReport.php')],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                $children[] = [$process, $pipes];
                stream_set_timeout($pipes[1], 20);
                fwrite($pipes[0], json_encode([
                    'database' => config('database.connections.report_schema'),
                    'sender' => $sender->id, 'post' => $targets[1]['subject']->id, 'uri' => $targets[1]['uri'],
                ], JSON_THROW_ON_ERROR)."\n");
                $this->assertSame("ready\n", fgets($pipes[1]));
            }
            foreach ($children as [$process, $pipes]) {
                fwrite($pipes[0], "go\n");
                fflush($pipes[0]);
            }
            $ids = [];
            foreach ($children as [$process, $pipes]) {
                $id = trim(fgets($pipes[1]) ?: '');
                $this->assertNotSame('', $id, stream_get_contents($pipes[2]));
                $ids[] = $id;
            }
            $this->assertSame($ids[0], $ids[1]);
            $this->assertDatabaseCount('reports', 1);
            $this->assertDatabaseCount('report_targets', 1);
            $this->assertDatabaseCount('report_receipts', 1);
        } finally {
            foreach ($children as [$process, $pipes]) {
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
                proc_terminate($process);
                proc_close($process);
            }
        }
    }

    public function test_receipt_and_target_queries_use_their_actual_indexes(): void
    {
        [$sender, $targets] = $this->reportFixture();
        $report = $this->storeReport($sender, $targets);
        $reports = $receipts = $rows = [];
        for ($i = 0; $i < 1000; $i++) {
            $id = (string) Str::uuid();
            $reports[] = ['id' => $id, 'origin' => 'federated', 'reason' => 'other', 'status' => 'open',
                'created_at' => now(), 'updated_at' => now()];
            $receipts[] = ['activity_hash' => hash('sha256', 'https://remote.example/reports/seed-'.$i), 'report_id' => $id];
            $rows[] = ['id' => (string) Str::uuid(), 'report_id' => $id, 'type' => 'post',
                'uri' => $targets[1]['uri'], 'uri_hash' => hash('sha256', $targets[1]['uri']),
                'unavailable' => false, 'post_id' => $targets[1]['subject']->id];
        }
        foreach (['reports' => $reports, 'report_receipts' => $receipts, 'report_targets' => $rows] as $table => $values) {
            foreach (array_chunk($values, 100) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
        $queries = [
            [DB::table('report_receipts')->where('activity_hash', hash('sha256', 'https://remote.example/reports/one')), 'PRIMARY'],
            [Report::query()->whereKey($report->id), 'PRIMARY'],
            [$report->targets()->getQuery(), 'report_targets_report_id_uri_hash_unique'],
        ];
        foreach ($queries as [$query, $index]) {
            $plan = DB::select('EXPLAIN FORMAT=TRADITIONAL '.$query->toSql(), $query->getBindings());
            $this->assertSame($index, $plan[0]->key, $query->toSql());
            $this->assertNotSame('ALL', $plan[0]->type);
        }
    }

    public function test_local_reference_resolution_uses_indexes_for_every_type(): void
    {
        [$sender, $targets] = $this->reportFixture();
        $actors = [];
        for ($i = 0; $i < 1000; $i++) {
            $actors[] = ['id' => (string) Str::uuid(), 'is_local' => false, 'type' => 'person',
                'preferred_username' => 'seed'.$i, 'domain' => 'seed.example',
                'uri' => 'https://seed.example/users/seed'.$i, 'status' => 'active'];
        }
        foreach (array_chunk($actors, 100) as $chunk) {
            DB::table('actors')->insert($chunk);
        }

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_starts_with($query->sql, 'select')) {
                $queries[] = [$query->sql, $query->bindings];
            }
        });
        $resolver = app(ObjectResolver::class);
        foreach ($targets as $target) {
            $reference = $resolver->resolveLocalReference($target['uri']);
            $this->assertSame($target['type'], $reference['type']);
            $this->assertTrue($reference['subject']->is($target['subject']));
        }
        $this->assertCount(5, $queries);
        foreach ($queries as [$sql, $bindings]) {
            $plan = DB::select('EXPLAIN FORMAT=TRADITIONAL '.$sql, $bindings);
            $this->assertNotNull($plan[0]->key, $sql);
            $this->assertNotSame('ALL', $plan[0]->type, $sql);
        }
    }

    public function test_details_migration_round_trip_does_not_silently_truncate_long_reports(): void
    {
        [$sender, $targets] = $this->reportFixture();
        $details = str_repeat('é', 40000);
        $report = $this->storeReport($sender, $targets, details: $details);
        $migration = require database_path('migrations/2026_10_23_000001_widen_report_details.php');
        try {
            $migration->down();
            $this->fail('Narrowing must not silently discard a federated motivation.');
        } catch (QueryException) {
            $this->assertSame($details, $report->fresh()->details);
        }
        $report->update(['details' => 'Short reason']);
        $migration->down();
        $this->assertSame('text', collect(Schema::getColumns('reports'))->firstWhere('name', 'details')['type_name']);
        $migration->up();
        $this->assertSame('longtext', collect(Schema::getColumns('reports'))->firstWhere('name', 'details')['type_name']);
        $this->assertSame('Short reason', $report->fresh()->details);
    }

    public function test_moderation_listing_pagination_and_previews_use_indexed_queries(): void
    {
        [$sender, $targets] = $this->reportFixture();
        $report = $this->storeReport($sender, $targets);
        $rows = $references = [];
        for ($i = 0; $i < 1000; $i++) {
            $id = (string) Str::uuid();
            $rows[] = ['id' => $id, 'origin' => 'federated', 'reason' => 'other',
                'reporter_uri' => $sender->uri, 'reporter_domain' => 'remote.example',
                'remote_activity_uri' => 'https://remote.example/reports/seed-'.$i,
                'details' => str_repeat('Evidence for moderation. ', 40),
                'status' => $i < 9 ? ['open', 'reviewed', 'actioned'][$i % 3] : 'dismissed',
                'created_at' => now()->subMinutes($i), 'updated_at' => now()];
            $references[] = ['id' => (string) Str::uuid(), 'report_id' => $id, 'type' => 'post',
                'uri' => $targets[1]['uri'], 'uri_hash' => hash('sha256', $targets[1]['uri']),
                'unavailable' => false, 'post_id' => $targets[1]['subject']->id];
        }
        foreach (['reports' => $rows, 'report_targets' => $references] as $table => $values) {
            foreach (array_chunk($values, 100) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
        $migration = require database_path('migrations/2026_10_23_000002_add_report_listing_indexes.php');
        $migration->down();
        $this->assertFalse(Schema::hasIndex('reports', 'reports_created_id_index'));
        $migration->up();
        $this->assertDatabaseCount('reports', 1001);
        DB::statement('ANALYZE TABLE reports, report_targets');
        $queries = app(ReportModerationQuery::class);
        foreach (['open', 'reviewed', 'dismissed', 'actioned', 'all'] as $status) {
            foreach ([0, 20] as $offset) {
                $query = $queries->listing($status)->offset($offset)->limit(20);
                $plan = DB::select('EXPLAIN FORMAT=TRADITIONAL '.$query->toSql(), $query->getBindings());
                $this->assertNotNull($plan[0]->key, $query->toSql().' '.json_encode($plan));
                $this->assertNotSame('ALL', $plan[0]->type, $query->toSql());
                $this->assertStringNotContainsString('filesort', strtolower($plan[0]->Extra ?? ''), $query->toSql());
            }
        }

        DB::enableQueryLog();
        $queries->previews($report, $targets[0]['subject']);
        $queries->listing('all')->paginate(20);
        $sqlQueries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($sqlQueries as $query) {
            if (! str_starts_with($query['query'], 'select')) {
                continue;
            }
            $plan = DB::select('EXPLAIN FORMAT=TRADITIONAL '.$query['query'], $query['bindings']);
            foreach ($plan as $step) {
                $this->assertFalse($step->type === 'ALL' && ($step->rows ?? 0) > 10, $query['query']);
            }
        }
    }
}
