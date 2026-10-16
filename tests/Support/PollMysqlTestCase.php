<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Opt-in random schemas; never migrates the configured application database. */
abstract class PollMysqlTestCase extends TestCase
{
    private ?string $temporarySchema = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var(env('OPENBOOK_TEST_POLLS_MYSQL', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('Set OPENBOOK_TEST_POLLS_MYSQL=1 to run isolated MySQL poll tests.');
        }

        $connection = array_replace(config('database.connections.mysql'), [
            'url' => null,
            'host' => env('OPENBOOK_TEST_MYSQL_HOST', '127.0.0.1'),
            'port' => env('OPENBOOK_TEST_MYSQL_PORT', '3307'),
            'unix_socket' => env('OPENBOOK_TEST_MYSQL_SOCKET', ''),
            'database' => null,
            'username' => env('OPENBOOK_TEST_MYSQL_USERNAME', 'root'),
            'password' => env('OPENBOOK_TEST_MYSQL_PASSWORD', ''),
            'prefix' => '',
        ]);
        config(['database.connections.poll_admin' => $connection]);
        $schema = 'openbook_polls_test_'.Str::lower(Str::random(16));
        DB::connection('poll_admin')->statement('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->temporarySchema = $schema;
        config(['database.connections.poll_schema' => array_replace($connection, ['database' => $schema]), 'database.default' => 'poll_schema']);
        try {
            Artisan::call('migrate', ['--database' => 'poll_schema', '--force' => true]);
        } catch (\Throwable $exception) {
            $this->dropTemporarySchema();
            config(['database.default' => 'sqlite']);
            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->dropTemporarySchema();
        } finally {
            DB::purge('poll_admin');
            config(['database.default' => 'sqlite']);
            parent::tearDown();
        }
    }

    private function dropTemporarySchema(): void
    {
        if ($this->temporarySchema !== null) {
            DB::purge('poll_schema');
            DB::connection('poll_admin')->statement('DROP DATABASE `'.$this->temporarySchema.'`');
            $this->temporarySchema = null;
        }
    }
}
