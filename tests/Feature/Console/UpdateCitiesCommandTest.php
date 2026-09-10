<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UpdateCitiesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_a_local_catalog_with_readable_administrative_names(): void
    {
        $directory = $this->fixtureDirectory();
        file_put_contents($directory.'/cities500.txt', $this->cityLine(1645528, 'Denpasar', 'ID', '02'));
        file_put_contents($directory.'/admin1CodesASCII.txt', "ID.02\tBali\tBali\t1650535\n");
        file_put_contents($directory.'/countryInfo.txt', "# comment\nID\tIDN\t360\tID\tIndonesia\n");

        $this->artisan('openbook:update-cities', ['file' => $directory.'/cities500.txt'])
            ->expectsOutputToContain('1 città importate')
            ->assertSuccessful();

        $this->assertDatabaseHas('geo_cities', [
            'geoname_id' => 1645528,
            'name' => 'Denpasar',
            'admin1_name' => 'Bali',
            'country_name' => 'Indonesia',
            'latitude_bucket' => -9,
            'longitude_bucket' => 115,
        ]);
    }

    public function test_a_malformed_catalog_does_not_replace_existing_cities(): void
    {
        DB::table('geo_cities')->insert([
            'geoname_id' => 1,
            'name' => 'Existing',
            'ascii_name' => 'Existing',
            'latitude' => 1,
            'longitude' => 1,
            'latitude_bucket' => 1,
            'longitude_bucket' => 1,
            'country_code' => 'IT',
            'feature_code' => 'PPL',
            'population' => 1,
            'catalog_batch' => '847ef4c2-1486-4a4f-a234-345436782f5f',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $directory = $this->fixtureDirectory();
        file_put_contents($directory.'/broken.txt', "not\ta\tvalid\tfile\n");

        $this->artisan('openbook:update-cities', ['file' => $directory.'/broken.txt'])
            ->assertFailed();

        $this->assertDatabaseHas('geo_cities', ['geoname_id' => 1, 'name' => 'Existing']);
    }

    private function fixtureDirectory(): string
    {
        $directory = storage_path('framework/testing/geonames-'.uniqid());
        mkdir($directory, 0777, true);

        return $directory;
    }

    private function cityLine(int $id, string $name, string $country, string $admin1): string
    {
        return implode("\t", [
            $id, $name, $name, '', '-8.6500', '115.2167', 'P', 'PPLA',
            $country, '', $admin1, '', '', '', '670210', '', '', 'Asia/Makassar', '2025-01-01',
        ])."\n";
    }
}
