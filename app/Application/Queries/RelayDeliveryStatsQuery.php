<?php

namespace App\Application\Queries;

use App\Application\Services\DeliverySource;
use App\Domain\Federation\Relay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class RelayDeliveryStatsQuery
{
    /**
     * @param  iterable<Relay>  $relays
     * @return array<string, array{today: int, lastSevenDays: int}>
     */
    public function forRelays(iterable $relays): array
    {
        $sourceIds = [];
        foreach ($relays as $relay) {
            $sourceIds[$relay->id] = DeliverySource::forRelay($relay)->key();
        }
        if ($sourceIds === []) {
            return [];
        }

        // Delivery buckets are recorded by UTC calendar day, including today.
        $today = CarbonImmutable::now('UTC');
        $rows = DB::table('stats_delivery_sources as s')
            ->join('stats_content_delivery_buckets as b', 'b.source_id', '=', 's.id')
            ->where('s.kind', 'relay')->whereIn('s.id', array_values(array_unique($sourceIds)))
            ->whereBetween('b.day', [$today->subDays(6)->toDateString(), $today->toDateString()])
            ->select('s.id')
            ->selectRaw('SUM(CASE WHEN b.day = ? THEN b.count ELSE 0 END) AS today', [$today->toDateString()])
            ->selectRaw('SUM(b.count) AS last_seven_days')
            ->groupBy('s.id')->get()->keyBy('id');

        $counts = [];
        foreach ($sourceIds as $relayId => $sourceId) {
            $row = $rows->get($sourceId);
            $counts[$relayId] = ['today' => (int) ($row?->today ?? 0),
                'lastSevenDays' => (int) ($row?->last_seven_days ?? 0)];
        }

        return $counts;
    }
}
