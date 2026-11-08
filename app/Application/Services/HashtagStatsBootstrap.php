<?php

namespace App\Application\Services;

use App\Application\Queries\StatsPostFilter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/** Explicit, repeatable period replacement; run with content ingestion paused. */
final class HashtagStatsBootstrap
{
    public function __construct(private readonly StatsPostFilter $filter, private readonly HashtagStats $stats) {}

    public function reset(): void
    {
        $writer = $this->stats->connection();
        $writer->transaction(function () use ($writer): void {
            $writer->table('stats_hashtag_daily_buckets')->delete();
            $writer->table('stats_hashtag_monthly_buckets')->delete();
            $writer->table('stats_collections')->where('metric', 'like', 'hashtag_seed:%')->delete();
        });
        app(CachedStatsDataset::class)->clearMetric('hashtags');
    }

    public function month(CarbonImmutable $month, ?Connection $writer = null): bool
    {
        $month = $month->utc()->startOfMonth();
        $now = CarbonImmutable::now('UTC');
        if ($month->lt($now->startOfMonth()->subMonthsNoOverflow(23)) || $month->gt($now)) {
            throw new RuntimeException('Month is outside the retained 24-month window.');
        }
        $lock = Cache::lock('hashtag-stats:bootstrap', 1800);
        if (! $lock->get()) {
            throw new RuntimeException('Another hashtag statistics bootstrap is running.');
        }
        try {
            $writer ??= $this->stats->connection();
            $metric = 'hashtag_seed:'.$month->format('Y-m');
            if ($writer->table('stats_collections')->where('metric', $metric)->exists()) {
                return false;
            }
            $end = $month->addMonth();
            $sqlite = $writer->getDriverName() === 'sqlite';
            $timezone = config('app.timezone');
            $query = $this->filter->posts($writer->table('posts as p')->join('actors as a', 'a.id', '=', 'p.actor_id'))
                ->join('post_hashtags as ph', 'ph.post_id', '=', 'p.id')
                ->where('p.published_at', '>=', $sqlite ? $month->setTimezone($timezone) : $month)
                ->where('p.published_at', '<', $sqlite ? $end->setTimezone($timezone) : $end)
                ->where('p.published_at', '<=', $sqlite ? $now->setTimezone($timezone) : $now);
            if ($sqlite) {
                $writer->getPdo()->sqliteCreateFunction('hashtag_stats_day', static fn ($value) => CarbonImmutable::parse($value, $timezone)->utc()->toDateString(), 1);
            }
            $day = $sqlite ? 'hashtag_stats_day(p.published_at)' : 'DATE(p.published_at)';
            $writer->transaction(function () use ($writer, $query, $metric, $month, $now, $day, $sqlite, $timezone): void {
                $writer->table('stats_hashtag_monthly_buckets')->where('month', $month->toDateString())->delete();
                $monthly = (clone $query)->select('ph.hashtag_id')->selectRaw('? AS month, COUNT(*) AS count', [$month->toDateString()])->groupBy('ph.hashtag_id');
                $writer->table('stats_hashtag_monthly_buckets')->insertUsing(['hashtag_id', 'month', 'count'], $monthly);
                $startDay = $now->subDays(29)->startOfDay()->max($month);
                if ($startDay->lt($month->addMonth())) {
                    $writer->table('stats_hashtag_daily_buckets')->whereBetween('day', [$startDay->toDateString(), $month->endOfMonth()->toDateString()])->delete();
                    $daily = (clone $query)->where('p.published_at', '>=', $sqlite ? $startDay->setTimezone($timezone) : $startDay)
                        ->select('ph.hashtag_id')->selectRaw("{$day} AS day, COUNT(*) AS count")->groupBy('ph.hashtag_id')->groupByRaw($day);
                    $writer->table('stats_hashtag_daily_buckets')->insertUsing(['hashtag_id', 'day', 'count'], $daily);
                }
                $writer->table('stats_collections')->insert(['metric' => $metric, 'started_at' => $now->format('Y-m-d H:i:s')]);
            });
            app(CachedStatsDataset::class)->clearMetric('hashtags');

            return true;
        } finally {
            $lock->release();
        }
    }
}
