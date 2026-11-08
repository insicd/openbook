<?php

namespace App\Console\Commands;

use App\Application\Services\HashtagStatsBootstrap;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class BackfillHashtagStatsCommand extends Command
{
    protected $signature = 'openbook:backfill-hashtag-stats {--month= : Initialize one month (YYYY-MM); omit to resume all 24 months}
        {--reset : Erase all hashtag buckets and rebuild from retained posts; previously pruned history is lost}';

    protected $description = 'Initialize historical hashtag buckets from retained posts, one atomic month at a time.';

    public function handle(HashtagStatsBootstrap $bootstrap): int
    {
        if (! app()->isDownForMaintenance()) {
            $this->error('Run php artisan down and pause ingestion/cron before backfilling. Then run php artisan up afterwards.');

            return self::FAILURE;
        }
        $value = $this->option('month');
        if ($value !== null && (! is_string($value) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $value))) {
            $this->error('Use --month=YYYY-MM.');

            return self::FAILURE;
        }
        if ($this->option('reset') && $value !== null) {
            $this->error('--reset cannot be combined with --month.');

            return self::FAILURE;
        }
        $lock = Cache::lock('hashtag-stats:rebuild', 7200);
        if (! $lock->get()) {
            $this->error('Another hashtag rebuild command is running.');

            return self::FAILURE;
        }
        try {
            if ($this->option('reset')) {
                $bootstrap->reset();
            }
            $now = CarbonImmutable::now('UTC')->startOfMonth();
            $months = $value !== null ? [CarbonImmutable::parse($value.'-01', 'UTC')] : array_map(fn ($offset) => $now->subMonthsNoOverflow($offset), range(23, 0));
            foreach ($months as $month) {
                try {
                    $done = $bootstrap->month($month);
                    $this->info($month->format('Y-m').($done ? ': initialized.' : ': already initialized; skipped.'));
                } catch (\Throwable $exception) {
                    $this->error($exception->getMessage());

                    return self::FAILURE;
                }
            }

            return self::SUCCESS;
        } finally {
            $lock->release();
        }

    }
}
