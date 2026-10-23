<?php

namespace App\Application\Services;

use App\Domain\Locations\GeoCity;
use Symfony\Component\Intl\Countries;

/** Completa le location remote senza modificare nomi o coordinate dichiarati. */
final class RemoteLocationEnricher
{
    /** @var array<string, string>|null */
    private ?array $countryCodesByName = null;

    public function __construct(private readonly NearestCityFinder $nearestCities) {}

    /** @param array<string, mixed> $location @return array<string, mixed> */
    public function enrich(array $location): array
    {
        $location += ['geo_city_id' => null, 'locality' => null, 'region' => null,
            'address' => null, 'country_code' => null, 'country_name' => null,
            'latitude' => null, 'longitude' => null];
        $rawCode = $location['country_code'];
        $location['country_code'] = $this->countryCode($rawCode)
            ?? $this->countryCode($location['country_name']);
        if ($rawCode !== null && $location['country_code'] === null) {
            $location['country_name'] ??= $rawCode;
        }

        if ($location['geo_city_id'] === null && config('openbook.locations.catalog_ready', false)) {
            $location = $location['latitude'] !== null && $location['longitude'] !== null
                ? $this->enrichLocationFromCoordinates($location)
                : $this->enrichLocationFromAddress($location);
        }
        if ($location['country_code'] !== null) {
            $location['country_name'] ??= Countries::getName($location['country_code'], 'en');
        }

        return $location;
    }

    private function countryCode(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        $code = strtoupper($value);
        if (Countries::exists($code)) {
            return $code;
        }
        if ($this->countryCodesByName === null) {
            $this->countryCodesByName = [];
            foreach (array_unique(['en', ...array_keys(config('openbook.locales', []))]) as $locale) {
                foreach (Countries::getNames($locale) as $countryCode => $name) {
                    $this->countryCodesByName[mb_strtolower($name)] = $countryCode;
                }
            }
        }

        return $this->countryCodesByName[mb_strtolower($value)] ?? null;
    }

    /**
     * Completa soltanto i dati geografici omessi dal server remoto,
     * mantenendo sempre prioritari quelli validi dichiarati dal server remoto.
     *
     * @param  array<string, mixed>  $location
     * @return array<string, mixed>
     */
    private function enrichLocationFromCoordinates(array $location): array
    {
        if ($location['latitude'] === null
            || $location['longitude'] === null) {
            return $location;
        }

        $city = $this->nearestCities->find($location['latitude'], $location['longitude']);

        if ($city === null) {
            return $location;
        }

        return $this->completeFromCity($location, $city);
    }

    /**
     * Usa soltanto nomi di citta' esatti nelle componenti finali di un
     * indirizzo strutturato, senza trasformare il centroide in coordinate
     * del luogo.
     *
     * @param  array<string, mixed>  $location
     * @return array<string, mixed>
     */
    private function enrichLocationFromAddress(array $location): array
    {
        $address = $location['address'] ?? $location['name'] ?? null;

        if ($location['geo_city_id'] !== null
            || $location['latitude'] !== null
            || $location['longitude'] !== null) {
            return $location;
        }

        $components = is_string($address) && str_contains($address, ',')
            ? array_slice(array_reverse(preg_split('/\s*,\s*/u', $address) ?: []), 0, 5)
            : [];
        $componentNames = array_map(fn (string $component): string => mb_strtolower(trim($component)), $components);
        $countryHint = $location['country_code'];
        foreach ($components as $component) {
            $countryHint ??= $this->countryCode($component);
        }
        $cityName = $location['locality'] ?? $location['name'] ?? null;
        $candidates = $cityName !== null ? [mb_strtolower($cityName) => $cityName] : [];

        foreach ($components as $component) {
            if ($this->countryCode($component) !== null) {
                continue;
            }
            $words = array_slice(preg_split('/\s+/u', trim($component, " \t\n\r\0\x0B.;")) ?: [], -6);

            for ($index = 0; $index < count($words); $index++) {
                $candidate = implode(' ', array_slice($words, $index));

                if (preg_match('/\pL/u', $candidate) === 1) {
                    $normalized = mb_strtolower($candidate);
                    $candidates[$normalized] ??= $candidate;
                }
            }
        }

        if ($candidates === []) {
            return $location;
        }

        $city = null;

        foreach ($candidates as $candidate) {
            $matches = GeoCity::query()
                ->where(fn ($query) => $query->where('name', $candidate)->orWhere('ascii_name', $candidate))
                ->when($countryHint !== null, fn ($query) => $query->where('country_code', $countryHint))
                ->orderByDesc('population')
                ->get();
            $city = $matches->first(fn (GeoCity $match): bool => in_array(
                mb_strtolower($match->country_name ?? $match->country_code),
                $componentNames,
                true,
            )) ?? $matches->first();

            if ($city !== null) {
                break;
            }
        }

        if ($city === null) {
            return $location;
        }

        return $this->completeFromCity($location, $city);
    }

    /** @param array<string, mixed> $location @return array<string, mixed> */
    private function completeFromCity(array $location, GeoCity $city): array
    {
        $location['geo_city_id'] = $city->geoname_id;
        $location['locality'] ??= $city->name;
        $location['region'] ??= $city->admin1_name;
        $location['country_code'] ??= $city->country_code;
        $location['country_name'] ??= $city->country_name;

        return $location;
    }
}
