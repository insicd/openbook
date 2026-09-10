<?php

namespace App\Domain\Locations;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $geoname_id
 * @property string $name
 * @property string $ascii_name
 * @property float $latitude
 * @property float $longitude
 * @property string $country_code
 * @property string|null $country_name
 * @property string|null $admin1_code
 * @property string|null $admin1_name
 * @property int $population
 */
class GeoCity extends Model
{
    protected $primaryKey = 'geoname_id';

    public $incrementing = false;

    protected $keyType = 'int';

    /** @var list<string> */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'geoname_id' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
            'population' => 'integer',
        ];
    }

    public function label(): string
    {
        return implode(', ', array_values(array_unique(array_filter([
            $this->name,
            $this->admin1_name,
            $this->country_name ?? $this->country_code,
        ]))));
    }
}
