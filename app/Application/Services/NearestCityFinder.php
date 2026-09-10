<?php

namespace App\Application\Services;

use App\Domain\Locations\GeoCity;

class NearestCityFinder
{
    public function find(float $latitude, float $longitude): ?GeoCity
    {
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return null;
        }

        foreach ([1, 2, 4, 8] as $bucketRadius) {
            $latitudeBuckets = range(
                max(-90, (int) floor($latitude) - $bucketRadius),
                min(90, (int) floor($latitude) + $bucketRadius),
            );
            $longitudeBuckets = array_values(array_unique(array_map(
                fn (int $bucket): int => $bucket < -180 ? $bucket + 360 : ($bucket > 179 ? $bucket - 360 : $bucket),
                range((int) floor($longitude) - $bucketRadius, (int) floor($longitude) + $bucketRadius),
            )));
            $candidates = GeoCity::query()
                ->whereIn('latitude_bucket', $latitudeBuckets)
                ->whereIn('longitude_bucket', $longitudeBuckets)
                ->get();

            if ($candidates->isEmpty()) {
                continue;
            }

            $nearest = $candidates
                ->map(fn (GeoCity $city) => [
                    'city' => $city,
                    'distance' => $this->distanceKm($latitude, $longitude, $city->latitude, $city->longitude),
                ])
                ->sortBy('distance')
                ->first();

            if ($nearest !== null && $nearest['distance'] <= (float) config('openbook.locations.nearest_city_max_km', 250)) {
                return $nearest['city'];
            }

            return null;
        }

        return null;
    }

    private function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $latitudeDelta = deg2rad($lat2 - $lat1);
        $longitudeDelta = deg2rad($lon2 - $lon1);
        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($longitudeDelta / 2) ** 2;

        return 6371.0088 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
