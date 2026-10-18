<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class UserTimezoneMysqlTest extends TestCase
{
    use CreatesAccounts;

    public function test_timezone_migration_round_trip_on_an_isolated_mysql_schema(): void
    {
        if (! filter_var(env('OPENBOOK_TEST_TIMEZONE_MYSQL', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('Set OPENBOOK_TEST_TIMEZONE_MYSQL=1 to run the isolated MySQL timezone test.');
        }

        $connection = array_replace(config('database.connections.mysql'), [
            'url' => null, 'database' => null, 'prefix' => '',
            'host' => env('OPENBOOK_TEST_MYSQL_HOST', '127.0.0.1'),
            'port' => env('OPENBOOK_TEST_MYSQL_PORT', '3307'),
            'unix_socket' => env('OPENBOOK_TEST_MYSQL_SOCKET', ''),
            'username' => env('OPENBOOK_TEST_MYSQL_USERNAME', 'root'),
            'password' => env('OPENBOOK_TEST_MYSQL_PASSWORD', ''),
        ]);
        config(['database.connections.timezone_admin' => $connection]);
        $schema = 'openbook_timezone_test_'.Str::lower(Str::random(16));
        DB::connection('timezone_admin')->statement('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        try {
            config(['database.connections.timezone_test' => array_replace($connection, ['database' => $schema]), 'database.default' => 'timezone_test']);
            Artisan::call('migrate', ['--database' => 'timezone_test', '--force' => true]);
            $user = $this->createFullAccount('mysqltimezone');
            $this->assertNull($user->settings->timezone);
            $migration = require database_path('migrations/2026_10_16_000000_make_user_timezone_nullable.php');

            $migration->down();
            $this->assertSame(config('app.timezone'), $user->settings->fresh()->timezone);
            $column = DB::selectOne("SHOW COLUMNS FROM user_settings LIKE 'timezone'");
            $this->assertSame('NO', $column->Null);
            $this->assertSame('UTC', $column->Default);

            $migration->up();
            $this->assertNull($user->settings->fresh()->timezone);
            $column = DB::selectOne("SHOW COLUMNS FROM user_settings LIKE 'timezone'");
            $this->assertSame('YES', $column->Null);
            $this->assertNull($column->Default);

            $user->settings->update(['timezone' => 'Europe/Helsinki']);
            $migration->down();
            $this->assertSame('Europe/Helsinki', $user->settings->fresh()->timezone);
            $migration->up();
        } finally {
            DB::purge('timezone_test');
            config(['database.default' => 'sqlite']);
            DB::connection('timezone_admin')->statement('DROP DATABASE `'.$schema.'`');
            DB::purge('timezone_admin');
        }
    }
}
