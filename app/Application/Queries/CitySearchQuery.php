<?php

namespace App\Application\Queries;

use App\Domain\Locations\GeoCity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CitySearchQuery
{
    /** @return Collection<int, GeoCity> */
    public function search(string $query, int $limit = 10): Collection
    {
        $query = trim(preg_replace('/[%_\\\\]+/u', ' ', $query) ?? '');
        $terms = array_values(array_filter(preg_split('/\s+/u', $query) ?: []));

        if ($terms === [] || mb_strlen($query) < 2) {
            return collect();
        }

        return GeoCity::query()
            ->where(function (Builder $builder) use ($terms): void {
                foreach ($terms as $term) {
                    $builder->where(function (Builder $termQuery) use ($term): void {
                        $prefix = $term.'%';
                        $termQuery
                            ->where('name', 'like', $prefix)
                            ->orWhere('ascii_name', 'like', $prefix)
                            ->orWhere('admin1_name', 'like', $prefix)
                            ->orWhere('country_name', 'like', $prefix);
                    });
                }
            })
            ->orderByDesc('population')
            ->orderBy('name')
            ->limit(max(1, min($limit, 20)))
            ->get();
    }
}
