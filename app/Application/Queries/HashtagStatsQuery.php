<?php

namespace App\Application\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class HashtagStatsQuery
{
    /** @return list<array{day: string, count: int}> */
    public function daily(string $hashtagId): array
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        try {
            $counts = DB::table('stats_hashtag_daily_buckets')->where('hashtag_id', $hashtagId)
                ->whereBetween('day', [$today->subDays(29)->toDateString(), $today->toDateString()])
                ->pluck('count', 'day');
        } catch (\Throwable) {
            return [];
        }
        $rows = [];
        for ($offset = 29; $offset >= 0; $offset--) {
            $day = $today->subDays($offset)->toDateString();
            $rows[] = ['day' => $day, 'count' => (int) ($counts[$day] ?? 0)];
        }

        return $rows;
    }
}
