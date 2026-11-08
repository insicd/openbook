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
            if ($sqlite) {
                $writer->getPdo()->sqliteCreateFunction('hashtag_stats_day', static fn ($value) => CarbonImmutable::parse($value, $timezone)->utc()->toDateString(), 1);
            }
            $sources = [];
            foreach (['posts' => ['p', 'post_hashtags', 'post_id'], 'events' => ['e', 'event_hashtags', 'event_id']] as $table => [$alias, $pivot, $foreignKey]) {
                $source = $writer->table($table.' as '.$alias)->join('actors as a', 'a.id', '=', $alias.'.actor_id');
                $source = $table === 'posts' ? $this->filter->posts($source) : $this->filter->events($source);
                $day = $sqlite ? 'hashtag_stats_day('.$alias.'.published_at)' : 'DATE('.$alias.'.published_at)';
                $sources[] = $source->join($pivot.' as tags', 'tags.'.$foreignKey, '=', $alias.'.id')
                    ->where($alias.'.published_at', '>=', $sqlite ? $month->setTimezone($timezone) : $month)
                    ->where($alias.'.published_at', '<', $sqlite ? $end->setTimezone($timezone) : $end)
                    ->where($alias.'.published_at', '<=', $sqlite ? $now->setTimezone($timezone) : $now)
                    ->select('tags.hashtag_id')->selectRaw($day.' AS day');
            }
            $query = $writer->query()->fromSub($sources[0]->unionAll($sources[1]), 'contributions');
            $writer->transaction(function () use ($writer, $query, $metric, $month, $now): void {
                $writer->table('stats_hashtag_monthly_buckets')->where('month', $month->toDateString())->delete();
                $monthly = (clone $query)->select('hashtag_id')->selectRaw('? AS month, COUNT(*) AS count', [$month->toDateString()])->groupBy('hashtag_id');
                $writer->table('stats_hashtag_monthly_buckets')->insertUsing(['hashtag_id', 'month', 'count'], $monthly);
                $startDay = $now->subDays(29)->startOfDay()->max($month);
                if ($startDay->lt($month->addMonth())) {
                    $writer->table('stats_hashtag_daily_buckets')->whereBetween('day', [$startDay->toDateString(), $month->endOfMonth()->toDateString()])->delete();
                    $daily = (clone $query)->where('day', '>=', $startDay->toDateString())
                        ->select('hashtag_id', 'day')->selectRaw('COUNT(*) AS count')->groupBy('hashtag_id')->groupBy('day');
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
