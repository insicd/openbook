<?php

namespace App\Application\Services;

use App\Application\Queries\StatsQuery;
use Carbon\CarbonImmutable;

final class StatsDataset
{
    private const MIN_COUNTRY_CONTENTS = 5;

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
            'local_count' => (int) $row->local_count,
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

    public function hashtags(): array
    {
        $to = CarbonImmutable::now('UTC')->startOfHour();
        $from = $to->startOfMonth()->subMonthsNoOverflow(23);
        $data = $this->query->hashtags($from, $to);
        $rows = $data->unique('id')->map(static fn (object $tag): array => [
            'id' => $tag->id,
            'name' => $tag->name,
            'count' => (int) $tag->total,
            'legend' => (int) $tag->overall_rank <= 10,
        ])->values()->all();
        $winners = $data->filter(static fn (object $tag): bool => (int) $tag->month_rank <= 10)->groupBy('month');
        $months = [];
        for ($month = $from; $month->lessThan($to); $month = $month->addMonth()) {
            $key = $month->format('Y-m');
            $counts = $winners->get($key, collect())->keyBy('id');
            $months[] = [
                'month' => $key,
                // Outside the monthly top ten means absent, not a zero count.
                'counts' => array_map(static fn (array $tag): ?int => $counts->has($tag['id']) ? (int) $counts->get($tag['id'])->count : null, $rows),
            ];
        }

        return $this->metadata('hashtags', $from, $to, 'month', 'posts') + ['rows' => $rows, 'months' => $months];
    }

    public function geography(): array
    {
        $to = CarbonImmutable::now('UTC')->startOfHour();
        $from = $to->subYearsNoOverflow(2);
        $data = $this->query->geography($from, $to);
        $total = 0;
        $countries = [];
        foreach ($data as $row) {
            if ($row->country === null) {
                $total = (int) $row->count;

                continue;
            }
            $country = preg_match('/^[A-Z]{2}$/', $row->country) ? $row->country : 'OTHER';
            $countries[$country] = ($countries[$country] ?? 0) + (int) $row->count;
        }
        $totals = ['contents' => $total, 'located' => array_sum($countries)];
        $other = $countries['OTHER'] ?? 0;
        unset($countries['OTHER']);
        foreach ($countries as $country => $count) {
            if ($count < self::MIN_COUNTRY_CONTENTS) {
                $other += $count;
                unset($countries[$country]);
            }
        }
        $rows = [];
        foreach ($countries as $country => $count) {
            $rows[] = ['country' => $country, 'count' => $count];
        }
        if ($other > 0) {
            $rows[] = ['country' => 'OTHER', 'count' => $other];
        }

        return $this->metadata('geography', $from, $to, 'period', 'contents') + ['rows' => $rows, 'totals' => $totals];
    }

    public function eventCalendar(): array
    {
        $to = CarbonImmutable::now('UTC')->startOfHour();
        $from = $to->subYearsNoOverflow(2);
        $rows = $this->query->eventCalendar($from, $to)->map(static fn (object $row): array => [
            'month' => $row->month,
            'count' => (int) $row->count,
        ])->all();

        return $this->metadata('eventCalendar', $from, $to, 'month', 'events') + ['rows' => $rows];
    }

    public function communityActivity(): array
    {
        $to = CarbonImmutable::now('UTC')->startOfHour();
        $from = $to->subYearsNoOverflow(2);
        $counts = $this->query->communityActivity($from, $to)->keyBy('week');
        $rows = [];
        for ($week = $from->startOfWeek(CarbonImmutable::MONDAY); $week->lessThan($to); $week = $week->addWeek()) {
            $key = $week->format('Y-m-d');
            $rows[] = [
                'week' => $key,
                'active' => (int) ($counts->get($key)?->active ?? 0),
                'count' => (int) ($counts->get($key)?->count ?? 0),
            ];
        }

        return $this->metadata('communityActivity', $from, $to, 'week', 'communities') + ['rows' => $rows];
    }

    public function media(): array
    {
        return $this->weeklyDataset('media', 'attachments', ['images', 'videos', 'image_posts', 'video_posts', 'posts']);
    }

    public function polls(): array
    {
        return $this->weeklyDataset('polls', 'posts', ['posts', 'polls']);
    }

    private function weeklyDataset(string $metric, string $unit, array $fields): array
    {
        $to = CarbonImmutable::now('UTC')->startOfHour();
        $from = $to->subYearsNoOverflow(2);
        $counts = $this->query->{$metric}($from, $to)->keyBy('week');
        $rows = [];
        for ($week = $from->startOfWeek(CarbonImmutable::MONDAY); $week->lessThan($to); $week = $week->addWeek()) {
            $key = $week->format('Y-m-d');
            $row = ['week' => $key];
            foreach ($fields as $field) {
                $row[$field] = (int) ($counts->get($key)?->{$field} ?? 0);
            }
            $rows[] = $row;
        }

        return $this->metadata($metric, $from, $to, 'week', $unit) + ['rows' => $rows];
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
