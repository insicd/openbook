<?php

namespace App\Domain\Locations;

use App\Domain\Posts\Post;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostLocation extends Model
{
    protected $primaryKey = 'post_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'post_id',
        'geo_city_id',
        'name',
        'admin1_name',
        'country_code',
        'country_name',
        'latitude',
        'longitude',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'geo_city_id' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(GeoCity::class, 'geo_city_id');
    }

    public function label(): string
    {
        $parts = [$this->name];
        $nameParts = array_map(
            fn (string $part): string => mb_strtolower(trim($part)),
            explode(',', $this->name),
        );

        foreach ([$this->admin1_name, $this->country_name ?? $this->country_code] as $part) {
            if ($part !== null && ! in_array(mb_strtolower($part), $nameParts, true)) {
                $parts[] = $part;
            }
        }

        return implode(', ', array_values(array_unique($parts)));
    }
}
