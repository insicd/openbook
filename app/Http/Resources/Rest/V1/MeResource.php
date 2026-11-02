<?php

namespace App\Http\Resources\Rest\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeResource extends JsonResource
{
    public static $wrap = null;

    /** @return array{myPerson: array<string, mixed>} */
    public function toArray(Request $request): array
    {
        return ['myPerson' => (new PersonResource($this->resource))->resolve($request)];
    }
}
