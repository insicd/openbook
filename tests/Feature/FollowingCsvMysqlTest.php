<?php

namespace Tests\Feature;

use App\Application\Services\FollowingCsvService;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class FollowingCsvMysqlTest extends TestCase
{
    use CreatesAccounts;

    public function test_export_pages_and_known_handle_lookup_use_indexes_on_mysql(): void
    {
        if (! filter_var(env('OPENBOOK_TEST_CSV_MYSQL', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('Set OPENBOOK_TEST_CSV_MYSQL=1 to check the export query plans.');
        }
        $connection = array_replace(config('database.connections.mysql'), [
            'url' => null, 'database' => null, 'prefix' => '',
            'host' => env('OPENBOOK_TEST_MYSQL_HOST', '127.0.0.1'),
            'port' => env('OPENBOOK_TEST_MYSQL_PORT', '3307'),
            'unix_socket' => env('OPENBOOK_TEST_MYSQL_SOCKET', ''),
            'username' => env('OPENBOOK_TEST_MYSQL_USERNAME', 'root'),
            'password' => env('OPENBOOK_TEST_MYSQL_PASSWORD', ''),
        ]);
        config(['database.connections.csv_admin' => $connection]);
        $schema = 'openbook_csv_test_'.Str::lower(Str::random(16));
        DB::connection('csv_admin')->statement('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            config(['database.connections.csv_test' => array_replace($connection, ['database' => $schema]), 'database.default' => 'csv_test']);
            Artisan::call('migrate', ['--database' => 'csv_test', '--force' => true]);
            $owner = $this->createFullAccount('csvowner');
            $other = $this->createFullAccount('csvother');
            $actors = $follows = [];
            for ($i = 0; $i < 1200; $i++) {
                $id = (string) Str::uuid();
                $actors[] = ['id' => $id, 'type' => 'person', 'is_local' => false,
                    'preferred_username' => 'csv'.$i, 'domain' => 'remote.example', 'uri' => 'https://remote.example/users/csv'.$i,
                    'status' => 'active'];
                foreach ([$owner, $other] as $user) {
                    $follows[] = ['id' => (string) Str::uuid(), 'follower_id' => $user->actor->id,
                        'following_id' => $id, 'status' => 'accepted', 'requested_at' => now(), 'accepted_at' => now()];
                }
            }
            $pendingId = (string) Str::uuid();
            $actors[] = ['id' => $pendingId, 'type' => 'person', 'is_local' => false,
                'preferred_username' => 'pending', 'domain' => 'remote.example',
                'uri' => 'https://remote.example/users/pending', 'status' => 'active'];
            $follows[] = ['id' => (string) Str::uuid(), 'follower_id' => $owner->actor->id,
                'following_id' => $pendingId, 'status' => 'pending', 'requested_at' => now(), 'accepted_at' => null];
            foreach (array_chunk($actors, 200) as $rows) {
                Actor::query()->insert($rows);
            }
            foreach (array_chunk($follows, 400) as $rows) {
                Follow::query()->insert($rows);
            }
            DB::statement('ANALYZE TABLE follows, actors');
            $pageQueries = [];
            DB::listen(function (QueryExecuted $query) use (&$pageQueries): void {
                if (str_starts_with($query->sql, 'select ') && str_contains($query->sql, 'from `follows`')) {
                    $pageQueries[] = [$query->sql, $query->bindings];
                }
            });
            $export = function () use ($owner): array {
                $stream = fopen('php://temp', 'w+');
                try {
                    app(FollowingCsvService::class)->writeExport($owner, $stream);
                    rewind($stream);

                    return array_map('str_getcsv', array_filter(explode("\n", stream_get_contents($stream))));
                } finally {
                    fclose($stream);
                }
            };
            $exported = $export();
            $rows = $exported;
            $this->assertSame(['Account address'], array_shift($rows));
            $expected = array_map(fn (int $i) => 'csv'.$i.'@remote.example', range(0, 1199));
            $this->assertEqualsCanonicalizing($expected, array_column($rows, 0));
            $this->assertCount(3, $pageQueries);
            foreach ($pageQueries as [$sql, $bindings]) {
                $plan = DB::select('EXPLAIN FORMAT=TRADITIONAL '.$sql, $bindings);
                $this->assertSame('follows_export_index', $plan[0]->key, json_encode($plan));
                $this->assertNotSame('ALL', $plan[0]->type);
                $this->assertContains('Using index', array_map('trim', explode(';', $plan[0]->Extra ?? '')));
                $this->assertStringNotContainsString('filesort', strtolower($plan[0]->Extra ?? ''));
            }
            $query = Actor::query()->where('preferred_username', 'csv0')->where('domain', 'remote.example')->where('is_local', false);
            $plan = DB::select('EXPLAIN FORMAT=TRADITIONAL '.$query->toSql(), $query->getBindings());
            $this->assertNotNull($plan[0]->key);
            $this->assertNotSame('ALL', $plan[0]->type);
            $migration = require database_path('migrations/2026_10_24_000000_add_follow_export_index.php');
            $migration->down();
            $this->assertFalse(DB::getSchemaBuilder()->hasIndex('follows', 'follows_export_index'));
            $this->assertSame($exported, $export());
            $migration->up();
            $this->assertTrue(DB::getSchemaBuilder()->hasIndex('follows', 'follows_export_index'));
            $this->assertSame($exported, $export());
        } finally {
            DB::purge('csv_test');
            config(['database.default' => 'sqlite']);
            DB::connection('csv_admin')->statement('DROP DATABASE `'.$schema.'`');
            DB::purge('csv_admin');
        }
    }
}
