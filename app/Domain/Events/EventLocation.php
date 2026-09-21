<?php

namespace App\Domain\Events;

use App\Domain\Locations\GeoCity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventLocation extends Model
{
    protected $primaryKey = 'event_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'event_id',
        'geo_city_id',
        'remote_uri',
        'url',
        'name',
        'address',
        'street_address',
        'locality',
        'region',
        'postal_code',
        'country_code',
        'country_name',
        'latitude',
        'longitude',
        'source',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'geo_city_id' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(GeoCity::class, 'geo_city_id');
    }
}
