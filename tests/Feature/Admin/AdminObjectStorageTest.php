<?php

namespace Tests\Feature\Admin;

use App\Application\Services\ObjectStorageSettings;
use App\Infrastructure\Database\SystemSetting;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class AdminObjectStorageTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function admin(): void
    {
        $user = $this->createFullAccount('storageadmin');
        $user->forceFill(['is_admin' => true])->save();
        $this->actingAs($user);
    }

    private function data(array $overrides = []): array
    {
        return array_replace([
            'provider' => 'cloudflareR2', 'account_id' => str_repeat('a', 32),
            'enabled' => '1', 'direct' => '0', 'bucket' => 'openbook-test',
            'access_key' => 'example-access-key', 'password' => 'example-secret',
        ], $overrides);
    }

    public function test_only_admins_can_read_save_or_verify(): void
    {
        $this->get(route('admin.object_storage.edit'))->assertRedirect(route('login'));
        $user = $this->createFullAccount('storageuser');
        foreach ([false, true] as $moderator) {
            $user->forceFill(['is_moderator' => $moderator])->save();
            $this->actingAs($user)->get(route('admin.object_storage.edit'))->assertForbidden();
            $this->put(route('admin.object_storage.update'), $this->data())->assertForbidden();
            $this->post(route('admin.object_storage.verify'))->assertForbidden();
        }
        $this->assertDatabaseMissing('system_settings', ['key' => ObjectStorageSettings::SECRET_KEY]);
    }

    public function test_admin_page_and_separate_settings_with_only_secret_encrypted(): void
    {
        $this->admin();
        $this->get(route('admin.object_storage.edit'))->assertOk()->assertSee('DigitalOcean Spaces')->assertSee('OVHcloud');
        $this->put(route('admin.object_storage.update'), $this->data())->assertSessionHasNoErrors()->assertRedirect(route('admin.object_storage.edit'));
        $stored = SystemSetting::query()->where('key', ObjectStorageSettings::SECRET_KEY)->value('value');
        $this->assertNotSame('example-secret', $stored);
        $this->assertSame('example-secret', Crypt::decryptString($stored));
        $this->assertDatabaseHas('system_settings', ['key' => 'object_storage_access_key', 'value' => 'example-access-key']);
        $this->assertDatabaseHas('system_settings', ['key' => 'object_storage_bucket', 'value' => 'openbook-test']);
        $this->get(route('admin.object_storage.edit'))->assertDontSee('example-secret');
        $this->put(route('admin.object_storage.update'), $this->data(['password' => '', 'enabled' => '0']))->assertSessionHasNoErrors();
        $this->assertSame('example-secret', SystemSetting::get(ObjectStorageSettings::SECRET_KEY));
        $this->assertFalse(app(ObjectStorageSettings::class)->values()['enabled']);
        $this->assertSame('example-secret', app(ObjectStorageSettings::class)->diskConfiguration()['secret']);
        $this->put(route('admin.object_storage.update'), $this->data(['password' => 'replacement']))->assertSessionHasNoErrors();
        $this->assertSame('replacement', SystemSetting::get(ObjectStorageSettings::SECRET_KEY));
    }

    public function test_validation_never_flashes_the_secret_and_requires_provider_fields(): void
    {
        $this->admin();
        $this->from(route('admin.object_storage.edit'))->put(route('admin.object_storage.update'), $this->data([
            'account_id' => 'invalid', 'direct' => '1',
        ]))->assertSessionHasErrors(['account_id', 'public_url'])->assertSessionMissing('_old_input.password');
        $this->assertDatabaseMissing('system_settings', ['key' => ObjectStorageSettings::SECRET_KEY]);
        $this->put(route('admin.object_storage.update'), $this->data(['password' => '']))->assertSessionHasErrors('password');
        foreach (['ovhcloud', 'other'] as $provider) {
            $this->put(route('admin.object_storage.update'), $this->data(['provider' => $provider]))->assertSessionHasErrors(['region', 'endpoint']);
        }
        foreach (['http://storage.example', 'https://user:pass@storage.example', 'https://storage.example/bucket'] as $endpoint) {
            $this->put(route('admin.object_storage.update'), $this->data(['provider' => 'other', 'region' => 'test-1', 'endpoint' => $endpoint]))->assertSessionHasErrors('endpoint');
        }
    }

    public function test_presets_build_fresh_disks_without_changing_local_configuration(): void
    {
        $settings = app(ObjectStorageSettings::class);
        $default = config('filesystems.default');
        $public = config('filesystems.disks.public');
        $presets = [
            ['cloudflareR2', '', '', 'https://'.str_repeat('a', 32).'.r2.cloudflarestorage.com', 'auto'],
            ['amazonS3', 'eu-west-1', '', null, 'eu-west-1'],
            ['digitalOcean', 'fra1', '', 'https://fra1.digitaloceanspaces.com', 'us-east-1'],
            ['ovhcloud', 'gra', 'https://s3.gra.io.cloud.ovh.net', 'https://s3.gra.io.cloud.ovh.net', 'gra'],
            ['other', 'custom-1', 'https://storage.example', 'https://storage.example', 'custom-1'],
        ];
        // Simulate an existing cached/env configuration: this must not win.
        config(['filesystems.disks.s3.key' => 'stale-key']);
        foreach ($presets as [$provider, $region, $endpoint, $expectedEndpoint, $expectedRegion]) {
            $settings->save($this->data(['provider' => $provider, 'region' => $region, 'endpoint' => $endpoint, 'path_style' => true]));
            $config = $settings->diskConfiguration();
            $this->assertSame($expectedEndpoint, $config['endpoint']);
            $this->assertSame($expectedRegion, $config['region']);
            $this->assertSame($provider === 'other', $config['use_path_style_endpoint']);
            $disk = $settings->disk();
            $this->assertSame('example-access-key', $disk->getClient()->getCredentials()->wait()->getAccessKeyId());
        }
        $oldDisk = $settings->disk();
        $settings->save($this->data(['access_key' => 'new-key', 'password' => 'new-secret']));
        $this->assertNotSame($oldDisk, $settings->disk());
        $this->assertSame('new-key', $settings->disk()->getClient()->getCredentials()->wait()->getAccessKeyId());
        $this->assertSame($default, config('filesystems.default'));
        $this->assertSame($public, config('filesystems.disks.public'));
    }

    public function test_connection_check_uses_real_adapter_commands_without_acl_and_cleans_up(): void
    {
        $this->admin();
        $settings = app(ObjectStorageSettings::class);
        $settings->save($this->data());
        $disk = $settings->disk();
        $commands = [];
        $contents = '';
        $path = '';
        $handler = new MockHandler;
        $handler->append(function (CommandInterface $command) use (&$commands, &$contents, &$path) {
            $commands[] = $command->getName();
            $this->assertFalse(isset($command['ACL']));
            $contents = (string) $command['Body'];
            $path = $command['Key'];

            return new Result;
        });
        $handler->append(function (CommandInterface $command) use (&$commands, &$contents, &$path) {
            $commands[] = $command->getName();
            $this->assertSame($path, $command['Key']);

            return new Result(['Body' => Utils::streamFor($contents)]);
        });
        $handler->append(function (CommandInterface $command) use (&$commands, &$path) {
            $commands[] = $command->getName();
            $this->assertSame($path, $command['Key']);

            return new Result;
        });
        $disk->getClient()->getHandlerList()->setHandler($handler);
        Storage::shouldReceive('build')->once()->with(Mockery::on(fn (array $config): bool => $config['http']['timeout'] === 15))->andReturn($disk);
        $this->post(route('admin.object_storage.verify'))->assertSessionHasNoErrors()->assertSessionHas('status', __('object_storage.result_ok'));
        $this->assertSame(['PutObject', 'GetObject', 'DeleteObject'], $commands);
        $this->assertStringStartsWith('openbook-connection-test/', $path);
    }

    public function test_failed_read_attempts_cleanup_and_does_not_expose_sdk_error(): void
    {
        $this->admin();
        $settings = app(ObjectStorageSettings::class);
        $settings->save($this->data());
        $disk = Mockery::mock(AwsS3V3Adapter::class);
        $disk->shouldReceive('getClient')->andReturn($settings->disk()->getClient());
        $disk->shouldReceive('put')->once()->andReturnTrue();
        $disk->shouldReceive('get')->once()->andThrow(new \RuntimeException('SECRET signed-url example-secret'));
        $disk->shouldReceive('delete')->once()->andReturnTrue();
        Storage::shouldReceive('build')->once()->andReturn($disk);
        $this->post(route('admin.object_storage.verify'))->assertSessionHasErrors('connection');
        $this->assertSame(__('object_storage.result_read'), session('errors')->first('connection'));
    }

    public function test_failed_write_reports_cleanup_failure_without_leaking_secrets(): void
    {
        $this->admin();
        $settings = app(ObjectStorageSettings::class);
        $settings->save($this->data());
        $disk = Mockery::mock(AwsS3V3Adapter::class);
        $disk->shouldReceive('getClient')->andReturn($settings->disk()->getClient());
        $disk->shouldReceive('put')->once()->andThrow(new \RuntimeException('example-secret'));
        $disk->shouldReceive('delete')->once()->andThrow(new \RuntimeException('example-secret'));
        Storage::shouldReceive('build')->once()->andReturn($disk);
        $this->post(route('admin.object_storage.verify'))->assertSessionHasErrors('connection');
        $this->assertSame(__('object_storage.result_write').' '.__('object_storage.cleanup_failed'), session('errors')->first('connection'));
    }

    public function test_delete_failure_is_not_reported_as_a_success_even_when_cleanup_retry_succeeds(): void
    {
        $settings = app(ObjectStorageSettings::class);
        $settings->save($this->data());
        $disk = Mockery::mock(AwsS3V3Adapter::class);
        $disk->shouldReceive('getClient')->andReturn($settings->disk()->getClient());
        $content = '';
        $disk->shouldReceive('put')->once()->andReturnUsing(function ($path, $contents) use (&$content) {
            $content = $contents;

            return true;
        });
        $disk->shouldReceive('get')->once()->andReturnUsing(function () use (&$content) {
            return $content;
        });
        $disk->shouldReceive('delete')->twice()->andReturn(false, true);
        Storage::shouldReceive('build')->once()->andReturn($disk);
        $this->assertSame(['stage' => 'delete', 'cleanup_failed' => false], $settings->verify());
    }

    public function test_saving_settings_invalidates_the_named_object_disk(): void
    {
        $settings = app(ObjectStorageSettings::class);
        $settings->save($this->data());
        $oldDisk = Storage::disk('objectStorage');
        $this->assertSame('example-access-key', $oldDisk->getClient()->getCredentials()->wait()->getAccessKeyId());
        $settings->save($this->data(['access_key' => 'replacement-key']));
        $newDisk = Storage::disk('objectStorage');
        $this->assertNotSame($oldDisk, $newDisk);
        $this->assertSame('replacement-key', $newDisk->getClient()->getCredentials()->wait()->getAccessKeyId());
    }

    public function test_undecryptable_secret_is_not_replaced_or_used(): void
    {
        $settings = app(ObjectStorageSettings::class);
        $settings->save($this->data());
        SystemSetting::query()->where('key', ObjectStorageSettings::SECRET_KEY)->update(['value' => 'broken-ciphertext']);
        $this->assertSame(['stage' => 'configuration', 'cleanup_failed' => false], $settings->verify());
        $this->assertDatabaseHas('system_settings', ['key' => ObjectStorageSettings::SECRET_KEY, 'value' => 'broken-ciphertext']);
    }
}
