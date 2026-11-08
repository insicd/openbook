<?php

namespace App\Application\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class HashtagStatsQuery
{
    /** @return list<array{day: string, count: int}> */
    public function daily(string $hashtagId): array
    {
        return $this->dailyForTags([$hashtagId], 30)[$hashtagId] ?? [];
    }

    /** @return array<string, list<array{day: string, count: int}>> */
    public function dailyForTags(array $hashtagIds, int $days = 7): array
    {
        if ($days < 1 || $days > 30) {
            throw new \InvalidArgumentException('Hashtag trends support 1 to 30 days.');
        }
        $hashtagIds = array_values(array_unique($hashtagIds));
        if ($hashtagIds === []) {
            return [];
        }
        $today = CarbonImmutable::now('UTC')->startOfDay();
        try {
            $counts = DB::table('stats_hashtag_daily_buckets')->whereIn('hashtag_id', $hashtagIds)
                ->whereBetween('day', [$today->subDays($days - 1)->toDateString(), $today->toDateString()])
                ->get(['hashtag_id', 'day', 'count'])->groupBy('hashtag_id');
        } catch (\Throwable) {
            return [];
        }
        $series = [];
        foreach ($hashtagIds as $id) {
            $tagCounts = ($counts[$id] ?? collect())->pluck('count', 'day');
            for ($offset = $days - 1; $offset >= 0; $offset--) {
                $day = $today->subDays($offset)->toDateString();
                $series[$id][] = ['day' => $day, 'count' => (int) ($tagCounts[$day] ?? 0)];
            }
        }

        return $series;
    }
}
