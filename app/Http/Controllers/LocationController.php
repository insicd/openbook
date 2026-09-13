<?php

namespace App\Http\Controllers;

use App\Application\Queries\CitySearchQuery;
use App\Application\Services\NearestCityFinder;
use App\Domain\Locations\GeoCity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function suggestions(Request $request, CitySearchQuery $search): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json([
            'suggestions' => $search->search((string) ($data['q'] ?? ''))
                ->map(fn (GeoCity $city): array => $this->present($city))
                ->values(),
        ]);
    }

    public function nearest(Request $request, NearestCityFinder $finder): JsonResponse
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);
        $city = $finder->find((float) $data['latitude'], (float) $data['longitude']);

        return response()->json([
            'location' => $city !== null ? $this->present($city) : null,
        ], $city !== null ? 200 : 404);
    }

    /** @return array<string, int|float|string|null> */
    private function present(GeoCity $city): array
    {
        return [
            'id' => $city->geoname_id,
            'label' => $city->label(),
            'name' => $city->name,
            'admin1_name' => $city->admin1_name,
            'country_code' => $city->country_code,
            'country_name' => $city->country_name,
            'latitude' => $city->latitude,
            'longitude' => $city->longitude,
        ];
    }
}
