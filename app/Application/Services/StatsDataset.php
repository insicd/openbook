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
        $counts = $this->query->rhythm($from, $to)->groupBy('bucket');
        $rows = [];
        for ($hour = $from; $hour->lessThan($to); $hour = $hour->addHour()) {
            $key = $hour->format('Y-m-d\TH:00:00\Z');
            $local = 0;
            $remote = 0;
            foreach ($counts->get($key, []) as $row) {
                if ($row->is_local) {
                    $local += (int) $row->count;
                } else {
                    $remote += (int) $row->count;
                }
            }
            $rows[] = ['at' => $key, 'local' => $local, 'remote' => $remote, 'total' => $local + $remote];
        }

        return $this->metadata('rhythm', $from, $to, 'hour', 'posts') + ['rows' => $rows];
    }

    public function languages(): array
    {
        $to = CarbonImmutable::now('UTC')->startOfHour();
        $from = $to->subYearsNoOverflow(2);
        $rows = $this->query->languages($from, $to)->map(static fn (object $row): array => [
            'week' => $row->week,
            'kind' => $row->kind,
            'language' => $row->language,
            'local' => (bool) $row->is_local,
            'count' => (int) $row->count,
        ])->all();

        return $this->metadata('languages', $from, $to, 'week', 'contents') + ['rows' => $rows];
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
