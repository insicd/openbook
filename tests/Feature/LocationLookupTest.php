<?php

namespace Tests\Feature;

use App\Domain\Locations\GeoCity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class LocationLookupTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_autocomplete_searches_city_and_administrative_names(): void
    {
        $user = $this->createFullAccount('locationsearch');
        $this->city(1645528, 'Denpasar', -8.65, 115.2167, 'Bali', 670210);
        $this->city(3169070, 'Roma', 41.8919, 12.5113, 'Lazio', 2318895);

        $this->actingAs($user)
            ->getJson(route('locations.suggest', ['q' => 'Bali']))
            ->assertOk()
            ->assertJsonCount(1, 'suggestions')
            ->assertJsonPath('suggestions.0.label', 'Denpasar, Bali, Indonesia');
    }

    public function test_current_coordinates_return_the_nearest_city_without_being_persisted(): void
    {
        $user = $this->createFullAccount('nearestcity');
        $this->city(658225, 'Helsinki', 60.1695, 24.9354, 'Uusimaa', 658864, 'FI', 'Finland');
        $this->city(588409, 'Tallinn', 59.437, 24.7535, 'Harjumaa', 394024, 'EE', 'Estonia');

        $this->actingAs($user)
            ->postJson(route('locations.nearest'), [
                'latitude' => 60.1708,
                'longitude' => 24.9375,
            ])
            ->assertOk()
            ->assertJsonPath('location.name', 'Helsinki')
            ->assertJsonPath('location.latitude', 60.1695)
            ->assertJsonPath('location.longitude', 24.9354);

        $this->assertDatabaseCount('post_locations', 0);
    }

    private function city(
        int $id,
        string $name,
        float $latitude,
        float $longitude,
        string $admin1,
        int $population,
        string $countryCode = 'ID',
        string $countryName = 'Indonesia',
    ): GeoCity {
        return GeoCity::query()->create([
            'geoname_id' => $id,
            'name' => $name,
            'ascii_name' => $name,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'latitude_bucket' => (int) floor($latitude),
            'longitude_bucket' => (int) floor($longitude),
            'country_code' => $countryCode,
            'country_name' => $countryName,
            'admin1_code' => '01',
            'admin1_name' => $admin1,
            'feature_code' => 'PPLA',
            'population' => $population,
            'catalog_batch' => '847ef4c2-1486-4a4f-a234-345436782f5f',
        ]);
    }
}
