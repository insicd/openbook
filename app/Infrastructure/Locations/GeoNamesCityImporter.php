<?php

namespace App\Infrastructure\Locations;

use App\Infrastructure\Database\SystemSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class GeoNamesCityImporter
{
    public const READY_SETTING_KEY = 'locations_catalog_ready';

    /**
     * @return array{imported: int, deleted: int}
     */
    public function import(string $citiesPath, ?string $admin1Path = null, ?string $countriesPath = null): array
    {
        $admin1 = $admin1Path !== null && is_file($admin1Path)
            ? $this->readAdmin1($admin1Path)
            : [];
        $countries = $countriesPath !== null && is_file($countriesPath)
            ? $this->readCountries($countriesPath)
            : [];
        $batch = (string) Str::uuid();
        $count = 0;

        foreach ($this->readCities($citiesPath, $admin1, $countries, $batch) as $_row) {
            $count++;
        }

        if ($count === 0) {
            throw new RuntimeException('Il file GeoNames non contiene città valide.');
        }

        return DB::transaction(function () use ($citiesPath, $admin1, $countries, $batch, $count): array {
            $chunk = [];

            foreach ($this->readCities($citiesPath, $admin1, $countries, $batch) as $row) {
                $chunk[] = $row;

                if (count($chunk) < 50) {
                    continue;
                }

                DB::table('geo_cities')->upsert(
                    $chunk,
                    ['geoname_id'],
                    [
                        'name', 'ascii_name', 'latitude', 'longitude',
                        'latitude_bucket', 'longitude_bucket', 'country_code',
                        'country_name', 'admin1_code', 'admin1_name',
                        'feature_code', 'population', 'catalog_batch', 'updated_at',
                    ],
                );
                $chunk = [];
            }

            if ($chunk !== []) {
                DB::table('geo_cities')->upsert(
                    $chunk,
                    ['geoname_id'],
                    [
                        'name', 'ascii_name', 'latitude', 'longitude',
                        'latitude_bucket', 'longitude_bucket', 'country_code',
                        'country_name', 'admin1_code', 'admin1_name',
                        'feature_code', 'population', 'catalog_batch', 'updated_at',
                    ],
                );
            }

            $deleted = DB::table('geo_cities')->where('catalog_batch', '!=', $batch)->delete();
            SystemSetting::putBool(self::READY_SETTING_KEY, true);

            return ['imported' => $count, 'deleted' => $deleted];
        });
    }

    /**
     * @param  array<string, string>  $admin1
     * @param  array<string, string>  $countries
     * @return \Generator<int, array<string, mixed>>
     */
    private function readCities(string $path, array $admin1, array $countries, string $batch): \Generator
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Impossibile leggere il file GeoNames: {$path}");
        }

        $lineNumber = 0;
        $now = now();

        try {
            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                $columns = explode("\t", rtrim($line, "\r\n"));

                if (count($columns) < 19) {
                    throw new RuntimeException("Riga GeoNames {$lineNumber} non valida: attese 19 colonne.");
                }

                [$id, $name, $asciiName, , $latitude, $longitude, $featureClass, $featureCode, $countryCode, , $admin1Code] = $columns;

                if (! ctype_digit($id) || $name === '' || $countryCode === '' || $featureClass !== 'P'
                    || filter_var($latitude, FILTER_VALIDATE_FLOAT) === false
                    || filter_var($longitude, FILTER_VALIDATE_FLOAT) === false) {
                    throw new RuntimeException("Riga GeoNames {$lineNumber} non valida.");
                }

                $lat = (float) $latitude;
                $lon = (float) $longitude;

                if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                    throw new RuntimeException("Coordinate GeoNames non valide alla riga {$lineNumber}.");
                }

                yield [
                    'geoname_id' => (int) $id,
                    'name' => mb_substr($name, 0, 200),
                    'ascii_name' => mb_substr($asciiName !== '' ? $asciiName : $name, 0, 200),
                    'latitude' => $lat,
                    'longitude' => $lon,
                    'latitude_bucket' => (int) floor($lat),
                    'longitude_bucket' => (int) floor($lon),
                    'country_code' => mb_substr(strtoupper($countryCode), 0, 2),
                    'country_name' => $countries[$countryCode] ?? null,
                    'admin1_code' => $admin1Code !== '' ? mb_substr($admin1Code, 0, 20) : null,
                    'admin1_name' => $admin1Code !== '' ? ($admin1[$countryCode.'.'.$admin1Code] ?? null) : null,
                    'feature_code' => mb_substr($featureCode, 0, 10),
                    'population' => ctype_digit($columns[14]) ? (int) $columns[14] : 0,
                    'catalog_batch' => $batch,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        } finally {
            fclose($handle);
        }

    }

    /** @return array<string, string> */
    private function readAdmin1(string $path): array
    {
        return $this->readMap($path, 0, 1);
    }

    /** @return array<string, string> */
    private function readCountries(string $path): array
    {
        return $this->readMap($path, 0, 4, true);
    }

    /** @return array<string, string> */
    private function readMap(string $path, int $keyColumn, int $valueColumn, bool $skipComments = false): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Impossibile leggere il file GeoNames: {$path}");
        }

        $values = [];

        try {
            while (($line = fgets($handle)) !== false) {
                if ($skipComments && str_starts_with($line, '#')) {
                    continue;
                }

                $columns = explode("\t", rtrim($line, "\r\n"));

                if (isset($columns[$keyColumn], $columns[$valueColumn]) && $columns[$keyColumn] !== '') {
                    $values[$columns[$keyColumn]] = $columns[$valueColumn];
                }
            }
        } finally {
            fclose($handle);
        }

        return $values;
    }
}
