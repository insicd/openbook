<?php

namespace Tests\Feature;

use App\Infrastructure\Database\SystemSetting;
use App\Infrastructure\Media\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
use Tests\TestCase;

class SystemSettingCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Keep transactions explicit so the HTTP cache path is exercised.
        $migration = require database_path('migrations/0001_01_01_000008_create_system_settings_table.php');
        $migration->up();
    }

    private function webRequest(): void
    {
        (new ReflectionProperty($this->app, 'isRunningInConsole'))->setValue($this->app, false);
        $this->app->instance('request', Request::create('/'));
    }

    private function settingReads(): int
    {
        return count(array_filter(DB::getQueryLog(), fn (array $query) => str_starts_with($query['query'], 'select') && str_contains($query['query'], 'system_settings')));
    }

    public function test_http_reads_cache_only_requested_keys_including_null_without_caching_defaults(): void
    {
        SystemSetting::put('first', 'value');
        SystemSetting::put('second', 'other');
        SystemSetting::put('null', null);
        SystemSetting::put('empty', '');
        SystemSetting::put('zero', '0');
        $this->webRequest();
        DB::enableQueryLog();

        $this->assertSame('value', SystemSetting::get('first'));
        $this->assertSame('value', SystemSetting::get('first'));
        $this->assertSame(1, $this->settingReads());
        $this->assertSame('other', SystemSetting::get('second'));
        $this->assertSame(2, $this->settingReads());
        foreach (['missing', 'null'] as $key) {
            $this->assertSame('fallback A', SystemSetting::get($key, 'fallback A'));
            $this->assertSame('fallback B', SystemSetting::get($key, 'fallback B'));
            $this->assertNull(SystemSetting::get($key));
        }
        $this->assertSame(4, $this->settingReads());
        $this->assertSame('', SystemSetting::get('empty', 'fallback'));
        $this->assertSame('0', SystemSetting::get('zero', 'fallback'));
        $this->assertFalse(SystemSetting::getBool('zero', true));
        $this->assertSame(6, $this->settingReads());

        SystemSetting::putBool('object_storage_direct', true);
        SystemSetting::put('object_storage_public_url', 'https://media.example');
        DB::flushQueryLog();
        foreach (range(1, 20) as $index) {
            $this->assertSame('https://media.example/image-'.$index.'.webp', app(MediaStorage::class)->publicUrl('image-'.$index.'.webp'));
        }
        $this->assertSame(2, $this->settingReads());
    }

    public function test_writes_invalidate_the_target_key_and_put_if_absent_refreshes_both_outcomes(): void
    {
        SystemSetting::put('retained', 'unchanged');
        SystemSetting::put('changed', 'old');
        $this->webRequest();
        $this->assertSame('unchanged', SystemSetting::get('retained'));
        $this->assertSame('old', SystemSetting::get('changed'));
        $this->assertNull(SystemSetting::get('new'));

        SystemSetting::put('changed', 'new value');
        $this->assertSame('new value', SystemSetting::get('changed'));
        SystemSetting::putBool('enabled', true);
        $this->assertTrue(SystemSetting::putIfAbsent('new', 'inserted'));
        // Simulate another writer winning before insertOrIgnore runs.
        DB::table('system_settings')->where('key', 'changed')->update(['value' => 'concurrent']);
        $this->assertFalse(SystemSetting::putIfAbsent('changed', 'ignored'));
        DB::enableQueryLog();

        $this->assertSame('unchanged', SystemSetting::get('retained'));
        $this->assertSame(0, $this->settingReads());
        $this->assertSame('concurrent', SystemSetting::get('changed'));
        $this->assertSame('inserted', SystemSetting::get('new'));
        $this->assertTrue(SystemSetting::getBool('enabled'));
        $this->assertSame(3, $this->settingReads());
        SystemSetting::put('changed', null);
        $this->assertSame('fallback', SystemSetting::get('changed', 'fallback'));
        SystemSetting::putBool('enabled', false);
        $this->assertFalse(SystemSetting::getBool('enabled'));
    }

    public function test_cli_reads_always_query_and_http_cache_is_discarded_between_requests(): void
    {
        $this->assertTrue($this->app->runningInConsole());
        SystemSetting::put('mode', 'first');
        DB::enableQueryLog();
        $this->assertSame('first', SystemSetting::get('mode'));
        $this->assertSame('first', SystemSetting::get('mode'));
        $this->assertSame(2, $this->settingReads());
        DB::table('system_settings')->where('key', 'mode')->update(['value' => 'second']);
        $this->assertSame('second', SystemSetting::get('mode'));
        $this->assertSame(3, $this->settingReads());

        $this->webRequest();
        $this->assertSame('second', SystemSetting::get('mode'));
        DB::table('system_settings')->where('key', 'mode')->update(['value' => 'third']);
        $this->assertSame('second', SystemSetting::get('mode'));
        $this->webRequest();
        $this->assertSame('third', SystemSetting::get('mode'));
        $this->assertSame(5, $this->settingReads());
    }

    public function test_encrypted_values_keep_their_storage_and_fallback_semantics(): void
    {
        SystemSetting::put('object_storage_secret', 'secret value');
        $stored = DB::table('system_settings')->where('key', 'object_storage_secret')->value('value');
        $this->assertNotSame('secret value', $stored);
        $this->assertSame('secret value', Crypt::decryptString($stored));
        $this->webRequest();
        DB::enableQueryLog();
        $this->assertSame('secret value', SystemSetting::get('object_storage_secret'));
        $this->assertSame('secret value', SystemSetting::get('object_storage_secret'));
        $this->assertSame(1, $this->settingReads());
        SystemSetting::put('object_storage_secret', 'replacement');
        $this->assertSame('replacement', SystemSetting::get('object_storage_secret'));
        DB::table('system_settings')->where('key', 'object_storage_secret')->update(['value' => 'invalid ciphertext']);
        $this->webRequest();
        DB::flushQueryLog();
        $this->assertSame('fallback A', SystemSetting::get('object_storage_secret', 'fallback A'));
        $this->assertSame('fallback B', SystemSetting::get('object_storage_secret', 'fallback B'));
        $this->assertSame(1, $this->settingReads());
    }

    public function test_transaction_reads_do_not_survive_rollback_or_commit(): void
    {
        SystemSetting::put('transaction', 'original');
        $this->webRequest();
        $this->assertSame('original', SystemSetting::get('transaction'));
        DB::beginTransaction();
        SystemSetting::put('transaction', 'uncommitted');
        $this->assertSame('uncommitted', SystemSetting::get('transaction'));
        DB::rollBack();
        $this->assertSame('original', SystemSetting::get('transaction'));

        DB::transaction(function (): void {
            SystemSetting::put('transaction', 'committed');
            $this->assertSame('committed', SystemSetting::get('transaction'));
        });
        $this->assertSame('committed', SystemSetting::get('transaction'));
    }
}
