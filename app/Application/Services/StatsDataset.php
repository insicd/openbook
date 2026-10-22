<?php

namespace App\Application\Services;

use App\Application\Queries\StatsQuery;
use Carbon\CarbonImmutable;

final class StatsDataset
{
    public function __construct(private readonly StatsQuery $query) {}

    public function rhythm(): array
    {
        $to = CarbonImmutable::now('UTC')->startOfHour();
        $from = $to->subHours(360);
        $counts = $this->query->rhythm($from, $to)->keyBy('bucket');
        $rows = [];
        for ($hour = $from; $hour->lessThan($to); $hour = $hour->addHour()) {
            $key = $hour->format('Y-m-d\TH:00:00\Z');
            $rows[] = ['at' => $key, 'total' => (int) ($counts->get($key)?->count ?? 0)];
        }

        return $this->metadata('rhythm', $from, $to, 'hour', 'posts') + ['rows' => $rows];
    }

    public function languages(): array
    {
        $to = CarbonImmutable::now('UTC')->startOfHour();
        $from = $to->subYearsNoOverflow(2);
        $rows = $this->query->languages($from, $to)->map(static fn (object $row): array => [
            'language' => $row->language,
            'count' => (int) $row->count,
        ])->all();

        return $this->metadata('languages', $from, $to, 'period', 'contents') + ['rows' => $rows];
    }

    public function domains(): array
    {
        $now = CarbonImmutable::now('UTC');
        $rows = $this->query->domains()->map(static fn (object $row): array => [
            'domain' => $row->domain,
            'type' => $row->type,
            'local' => (bool) $row->is_local,
            'count' => (int) $row->count,
        ])->all();

        return $this->metadata('domains', $now, $now, 'snapshot', 'actors') + ['rows' => $rows];
    }

    public function weekly(): array
    {
        $to = CarbonImmutable::now('UTC')->startOfHour();
        $from = $to->subYearsNoOverflow(2);
        $rows = $this->query->weekly($from, $to)->map(static fn (object $row): array => [
            'week' => $row->week,
            'count' => (int) $row->count,
            'authors' => (int) $row->authors,
        ])->all();
        $weeks = [];
        for ($week = $from->startOfWeek(CarbonImmutable::MONDAY); $week->lessThan($to); $week = $week->addWeek()) {
            $weeks[] = $week->format('Y-m-d');
        }

        return $this->metadata('weekly', $from, $to, 'week', 'posts_and_authors') + ['rows' => $rows, 'weeks' => $weeks];
    }

    private function metadata(string $metric, CarbonImmutable $from, CarbonImmutable $to, string $bucket, string $unit): array
    {
        return [
            'version' => 1,
            'metric' => $metric,
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'timezone' => 'UTC'],
            'bucket' => $bucket,
            'unit' => $unit,
            'coverage' => ['mode' => 'retained_corpus', 'historical_completeness' => 'unknown'],
            'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ];
    }
}
