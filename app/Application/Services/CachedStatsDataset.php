<?php

namespace App\Application\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class CachedStatsDataset
{
    public function __construct(private readonly StatsDataset $datasets) {}

    public function get(string $metric): array
    {
        if (! array_key_exists($metric, config('stats.ttl', []))) {
            throw new InvalidArgumentException('Unknown stats dataset.');
        }
        $ttl = max(300, (int) config('stats.ttl.'.$metric));
        // Never publish a snapshot containing uncommitted changes.
        if (DB::connection()->transactionLevel() > 0) {
            return $this->uncached($metric, $ttl);
        }
        try {
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $generation = $this->generation();
                $key = $this->key($metric, $generation);
                $data = $this->cache(fn () => Cache::get($key));
                if (is_array($data) && $this->generation() === $generation) {
                    return $data;
                }
                $lock = $this->cache(fn () => Cache::lock($key.':lock', 120));
                try {
                    $this->cache(fn () => $lock->block(3));
                    if ($this->generation() !== $generation) {
                        continue;
                    }
                    $data = $this->cache(fn () => Cache::get($key));
                    if (! is_array($data)) {
                        $data = $this->uncached($metric, $ttl);
                        if ($this->generation() !== $generation) {
                            continue;
                        }
                        $at = CarbonImmutable::now('UTC');
                        $data['cache']['cached_at'] = $at->toIso8601String();
                        $data['cache']['expires_at'] = $at->addSeconds($ttl)->toIso8601String();
                        try {
                            if (! $this->cache(fn () => Cache::put($key, $data, $ttl))) {
                                $data['cache']['cached_at'] = $data['cache']['expires_at'] = null;
                            }
                        } catch (StatsCacheUnavailable) {
                            $data['cache']['cached_at'] = $data['cache']['expires_at'] = null;

                            return $data;
                        }
                    }
                    if ($this->generation() === $generation) {
                        return $data;
                    }
                } finally {
                    try {
                        $lock->release();
                    } catch (Throwable) {
                        // A failed unlock expires with the lease.
                    }
                }
            }
        } catch (StatsCacheUnavailable|LockTimeoutException) {
            // Shared hosting must remain usable when the cache is unavailable.
        }

        return $this->uncached($metric, $ttl);
    }

    /** Rotating a namespace also fences out computations already in flight. */
    public function clear(): bool
    {
        try {
            return (bool) Cache::forever('stats:generation', (string) Str::uuid());
        } catch (Throwable) {
            return false;
        }
    }

    private function generation(): string
    {
        $key = 'stats:generation';
        $token = $this->cache(fn () => Cache::get($key));
        if (! is_string($token)) {
            $this->cache(fn () => Cache::add($key, (string) Str::uuid(), 315360000));
            $token = $this->cache(fn () => Cache::get($key));
        }
        if (! is_string($token)) {
            throw new StatsCacheUnavailable;
        }

        return $token;
    }

    private function key(string $metric, string $generation): string
    {
        // Only expiry or the explicit admin command refreshes a cached dataset.
        // Windows and moderation settings are evaluated when computing a miss.
        return 'stats:v3:'.$metric.':'.$generation;
    }

    private function uncached(string $metric, int $ttl): array
    {
        return $this->datasets->{$metric}() + ['cache' => ['ttl_seconds' => $ttl, 'cached_at' => null, 'expires_at' => null]];
    }

    private function cache(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (LockTimeoutException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new StatsCacheUnavailable(previous: $e);
        }
    }
}
