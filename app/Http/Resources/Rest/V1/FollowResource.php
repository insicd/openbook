<?php

namespace App\Http\Resources\Rest\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FollowResource extends JsonResource
{
    public static $wrap = null;

    /** @return array{followStatus: string} */
    public function toArray(Request $request): array
    {
        return ['followStatus' => $this->resource];
    }
}
