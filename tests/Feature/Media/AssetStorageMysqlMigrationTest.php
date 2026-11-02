<?php

namespace Tests\Feature\Media;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AssetStorageMysqlMigrationTest extends TestCase
{
    public function test_location_migration_up_and_down_on_mysql_temporary_tables(): void
    {
        if (! filter_var(env('OPENBOOK_TEST_ASSETS_MYSQL', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('Set OPENBOOK_TEST_ASSETS_MYSQL=1 for isolated MySQL asset migration checks.');
        }
        $connection = array_replace(config('database.connections.mysql'), [
            'url' => null, 'prefix' => '', 'unix_socket' => '',
            'host' => env('OPENBOOK_TEST_MYSQL_HOST', '127.0.0.1'),
            'port' => env('OPENBOOK_TEST_MYSQL_PORT', '3307'),
            'database' => env('OPENBOOK_TEST_MYSQL_DATABASE', 'openbook_test'),
            'username' => env('OPENBOOK_TEST_MYSQL_USERNAME', 'openbook_test'),
            'password' => env('OPENBOOK_TEST_MYSQL_PASSWORD', ''),
            'options' => [\PDO::ATTR_TIMEOUT => 2],
        ]);
        config(['database.connections.asset_migration' => $connection]);
        try {
            DB::connection('asset_migration')->getPdo();
        } catch (\Throwable) {
            DB::purge('asset_migration');
            $this->markTestSkipped('Dedicated MySQL test server unavailable.');
        }
        try {
            config(['database.default' => 'asset_migration']);
            // Temporary tables shadow names only in this connection; no real
            // table, schema or customer data is dropped or modified.
            DB::statement('CREATE TEMPORARY TABLE profiles (id VARCHAR(36) PRIMARY KEY, bio TEXT, avatar_path VARCHAR(255), cover_path VARCHAR(255))');
            DB::statement('CREATE TEMPORARY TABLE actors (id VARCHAR(36) PRIMARY KEY, icon_url TEXT, image_url TEXT, is_local BOOLEAN NOT NULL DEFAULT 1)');
            DB::statement('CREATE TEMPORARY TABLE communities (id VARCHAR(36) PRIMARY KEY, actor_id VARCHAR(36) NOT NULL UNIQUE)');
            DB::table('profiles')->insert(['id' => 'legacy', 'avatar_path' => 'avatars/old.jpg']);
            $migration = require database_path('migrations/2026_11_02_000000_add_asset_storage_locations.php');
            $migration->up();
            $this->assertSame('public', DB::table('profiles')->first()->avatar_disk);
            $this->assertSame('public', DB::table('profiles')->first()->cover_disk);
            $this->assertSame(['id', 'bio', 'avatar_disk', 'avatar_path', 'cover_disk', 'cover_path'], array_column(DB::select('SHOW COLUMNS FROM profiles'), 'Field'));
            DB::table('actors')->insert(['id' => 'local', 'icon_disk' => 'objectStorage', 'icon_path' => 'avatars/new.jpg']);
            $this->assertSame('objectStorage', DB::table('actors')->first()->icon_disk);
            DB::table('communities')->insert(['id' => 'club', 'actor_id' => 'local']);
            $relocation = require database_path('migrations/2026_11_02_000001_move_community_asset_locations.php');
            $relocation->up();
            $this->assertSame('avatars/new.jpg', DB::table('communities')->first()->avatar_path);
            $this->assertSame('objectStorage', DB::table('communities')->first()->avatar_disk);
            $this->assertSame(['avatar_disk', 'avatar_path', 'cover_disk', 'cover_path'], array_slice(array_column(DB::select('SHOW COLUMNS FROM communities'), 'Field'), -4));
            $this->assertFalse(property_exists(DB::table('actors')->first(), 'icon_path'));
            $relocation->down();
            $this->assertSame('avatars/new.jpg', DB::table('actors')->first()->icon_path);
            $migration->down();
            $this->assertSame('avatars/old.jpg', DB::table('profiles')->first()->avatar_path);
            $this->assertFalse(property_exists(DB::table('profiles')->first(), 'avatar_disk'));
            $this->assertFalse(property_exists(DB::table('actors')->first(), 'icon_disk'));
        } finally {
            DB::purge('asset_migration');
            config(['database.default' => 'sqlite']);
        }
    }
}
