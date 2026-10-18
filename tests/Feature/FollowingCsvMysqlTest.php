<?php

namespace Tests\Feature;

use App\Application\Services\FollowingCsvService;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
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
            foreach (array_chunk($actors, 200) as $rows) {
                Actor::query()->insert($rows);
            }
            foreach (array_chunk($follows, 400) as $rows) {
                Follow::query()->insert($rows);
            }
            DB::statement('ANALYZE TABLE follows, actors');
            $base = app(FollowingCsvService::class)->exportQuery($owner);
            foreach ([null, (clone $base)->orderBy('following_id')->limit(500)->pluck('following_id')->last()] as $cursor) {
                $query = clone $base;
                if ($cursor !== null) {
                    $query->where('following_id', '>', $cursor);
                }
                $query->orderBy('following_id')->limit(500);
                $plan = DB::select('EXPLAIN FORMAT=TRADITIONAL '.$query->toSql(), $query->getBindings());
                $this->assertSame('follows_follower_id_following_id_unique', $plan[0]->key, json_encode($plan));
                $this->assertNotSame('ALL', $plan[0]->type);
                $this->assertStringNotContainsString('filesort', strtolower($plan[0]->Extra ?? ''));
            }
            $query = Actor::query()->where('preferred_username', 'csv0')->where('domain', 'remote.example')->where('is_local', false);
            $plan = DB::select('EXPLAIN FORMAT=TRADITIONAL '.$query->toSql(), $query->getBindings());
            $this->assertNotNull($plan[0]->key);
            $this->assertNotSame('ALL', $plan[0]->type);
            $stream = fopen('php://temp', 'w+');
            app(FollowingCsvService::class)->writeExport($owner, $stream);
            rewind($stream);
            $this->assertSame(1201, count(array_filter(explode("\n", stream_get_contents($stream)))));
            fclose($stream);
        } finally {
            DB::purge('csv_test');
            config(['database.default' => 'sqlite']);
            DB::connection('csv_admin')->statement('DROP DATABASE `'.$schema.'`');
            DB::purge('csv_admin');
        }
    }
}
