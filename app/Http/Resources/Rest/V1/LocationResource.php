<?php

namespace App\Http\Resources\Rest\V1;

use App\Domain\Locations\PostLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LocationResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'locationName' => $this->resource->name,
            'locationRegion' => $this->resource instanceof PostLocation ? $this->resource->admin1_name : $this->resource->region,
            'locationCountryCode' => $this->resource->country_code,
            'locationCountryName' => $this->resource->country_name,
            'locationLatitude' => $this->resource->latitude,
            'locationLongitude' => $this->resource->longitude,
        ];
    }
}
