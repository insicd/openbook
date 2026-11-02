<?php

namespace App\Infrastructure\Media;

use App\Infrastructure\Database\SystemSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class MediaStorage
{
    public const OBJECT_DISK = 'objectStorage';

    public function uploadDisk(): string
    {
        return SystemSetting::getBool('object_storage_enabled') ? self::OBJECT_DISK : 'public';
    }

    public function url(Media $media, ?MediaVariant $variant = null): string
    {
        $disk = $variant?->disk ?? $media->disk;
        $path = $variant?->path ?? $media->path;
        if ($disk !== self::OBJECT_DISK) {
            return Storage::disk($disk)->url($path);
        }
        if ($url = $this->publicUrl($path)) {
            return $url;
        }

        return route('media.files.show', ['media' => $media->id, 'variant' => $variant === null ? null : 'thumbnail']);
    }

    public function publicUrl(string $path): ?string
    {
        $base = SystemSetting::getBool('object_storage_direct') ? SystemSetting::get('object_storage_public_url') : null;

        return filled($base) ? rtrim($base, '/').'/'.implode('/', array_map('rawurlencode', explode('/', $path))) : null;
    }

    /** @param list<string> $paths */
    public function storeFiles(string $disk, array $paths, \Closure $store): mixed
    {
        $cleaned = false;
        $cleanup = function () use ($disk, $paths, &$cleaned): void {
            if (! $cleaned) {
                $cleaned = app(MediaStorage::class)->cleanup($disk, $paths);
            }
        };
        // Savepoint rollback callbacks do not survive their commit, so attach
        // cleanup to the enclosing publication transactions as well.
        foreach (app('db.transactions')->getPendingTransactions() as $transaction) {
            if ($transaction->connection === DB::connection()->getName()) {
                $transaction->addCallbackForRollback($cleanup);
            }
        }
        try {
            return DB::transaction($store);
        } catch (Throwable) {
            $cleanup();
            throw ValidationException::withMessages(['images' => __('object_storage.upload_failed')]);
        }
    }

    /** @param list<string> $paths */
    public function cleanup(string $disk, array $paths): bool
    {
        try {
            if (! Storage::disk($disk)->delete($paths)) {
                throw new \RuntimeException;
            }

            return true;
        } catch (Throwable) {
            // Never log the SDK exception: it may contain signed requests.
            Log::warning('Media cleanup failed.', ['disk' => $disk, 'paths' => $paths]);

            return false;
        }
    }
}
