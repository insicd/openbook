<?php

namespace App\Application\Services;

use App\Infrastructure\Database\SystemSetting;
use Aws\CommandInterface;
use Aws\Middleware;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ObjectStorageSettings
{
    public const SECRET_KEY = 'object_storage_secret';

    public const PROVIDERS = ['cloudflareR2', 'amazonS3', 'digitalOcean', 'ovhcloud', 'other'];

    public const FIELDS = ['enabled', 'direct', 'provider', 'account_id', 'bucket', 'region', 'endpoint', 'access_key', 'path_style', 'public_url'];

    /** @return array<string, string|bool> */
    public function values(): array
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $values[$field] = in_array($field, ['enabled', 'direct', 'path_style'], true)
                ? SystemSetting::getBool('object_storage_'.$field)
                : (SystemSetting::get('object_storage_'.$field) ?? ($field === 'provider' ? 'cloudflareR2' : ''));
        }

        return $values;
    }

    public function hasSecret(): bool
    {
        return SystemSetting::query()->where('key', self::SECRET_KEY)->whereNotNull('value')->exists();
    }

    /** @param array<string, mixed> $data */
    public function save(array $data): void
    {
        DB::transaction(function () use ($data): void {
            foreach (self::FIELDS as $field) {
                if (in_array($field, ['enabled', 'direct', 'path_style'], true)) {
                    SystemSetting::putBool('object_storage_'.$field, (bool) ($data[$field] ?? false));
                } else {
                    SystemSetting::put('object_storage_'.$field, $data[$field] ?? '');
                }
            }
            if (filled($data['password'] ?? null)) {
                SystemSetting::put(self::SECRET_KEY, $data['password']);
            }
        });
        Storage::forgetDisk('objectStorage');
    }

    /** @return array<string, mixed> */
    public function diskConfiguration(): array
    {
        $values = $this->values();
        $secret = SystemSetting::get(self::SECRET_KEY);
        if (! filled($secret) || ! filled($values['access_key']) || ! filled($values['bucket'])) {
            throw new RuntimeException(__('object_storage.configuration_missing'));
        }

        $endpoint = $values['endpoint'];
        $region = $values['region'];
        switch ($values['provider']) {
            case 'cloudflareR2':
                if (! preg_match('/^[a-f0-9]{32}$/i', $values['account_id'])) {
                    throw new RuntimeException(__('object_storage.configuration_missing'));
                }
                $endpoint = 'https://'.$values['account_id'].'.r2.cloudflarestorage.com';
                $region = 'auto';
                break;
            case 'amazonS3':
                $endpoint = null;
                break;
            case 'digitalOcean':
                if (! preg_match('/^[a-z0-9-]+$/', $region)) {
                    throw new RuntimeException(__('object_storage.configuration_missing'));
                }
                $endpoint = 'https://'.$region.'.digitaloceanspaces.com';
                $region = 'us-east-1';
                break;
            case 'ovhcloud':
            case 'other':
                if (! filled($endpoint)) {
                    throw new RuntimeException(__('object_storage.configuration_missing'));
                }
                break;
            default:
                throw new RuntimeException(__('object_storage.configuration_missing'));
        }
        if (! filled($region)) {
            throw new RuntimeException(__('object_storage.configuration_missing'));
        }

        return [
            'driver' => 's3',
            'key' => $values['access_key'],
            'secret' => $secret,
            'region' => $region,
            'bucket' => $values['bucket'],
            'endpoint' => $endpoint,
            'url' => $values['public_url'] ?: null,
            'use_path_style_endpoint' => $values['provider'] === 'other' && $values['path_style'],
            'stream_reads' => true,
            // Application upload limits fit a single PUT; avoid abandoned
            // multipart uploads when a transfer fails. Streams stay on disk.
            'options' => ['mup_threshold' => PHP_INT_MAX],
            'throw' => true,
            'report' => false,
            'http' => ['connect_timeout' => 5, 'timeout' => 300, 'read_timeout' => 30],
            'retries' => 0,
        ];
    }

    public function disk(int $timeout = 300): AwsS3V3Adapter
    {
        // Build on demand: neither cached config nor a previously resolved disk
        // may retain credentials after an administrator updates the settings.
        $configuration = $this->diskConfiguration();
        $configuration['http']['timeout'] = $timeout;
        $disk = Storage::build($configuration);
        // Bucket policy controls public access. Flysystem otherwise adds an ACL
        // even for private writes, which ACL-disabled S3 buckets may reject.
        $disk->getClient()->getHandlerList()->appendInit(Middleware::mapCommand(function (CommandInterface $command): CommandInterface {
            unset($command['ACL']);

            return $command;
        }), 'openbook_no_object_acl');

        return $disk;
    }

    /** @return array{stage: string, cleanup_failed: bool} */
    public function verify(): array
    {
        $stage = 'configuration';
        $cleanupFailed = false;
        $disk = null;
        $path = 'openbook-connection-test/'.Str::uuid().'.txt';
        $contents = 'OpenBook '.Str::random(32);
        try {
            $disk = $this->disk(15);
            $stage = 'write';
            if (! $disk->put($path, $contents)) {
                throw new RuntimeException;
            }
            $stage = 'read';
            if ($disk->get($path) !== $contents) {
                throw new RuntimeException;
            }
            $stage = 'delete';
            if (! $disk->delete($path)) {
                throw new RuntimeException;
            }
            $stage = 'ok';
        } catch (Throwable) {
            // SDK exceptions can contain signed URLs and credentials. Return only
            // the operation that failed, never the original exception message.
        } finally {
            if ($disk !== null && $stage !== 'ok') {
                try {
                    $cleanupFailed = ! $disk->delete($path);
                } catch (Throwable) {
                    $cleanupFailed = true;
                }
            }
        }

        return ['stage' => $stage, 'cleanup_failed' => $cleanupFailed];
    }
}
