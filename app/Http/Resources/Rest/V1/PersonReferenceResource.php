<?php

namespace App\Http\Resources\Rest\V1;

use App\Federation\Actors\Actor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PersonReferenceResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, string> */
    public function toArray(Request $request): array
    {
        /** @var Actor $actor */
        $actor = $this->resource;
        $data = [
            'personId' => $actor->id,
            'personUsername' => $actor->preferred_username,
            'personDomain' => $actor->domain,
            'personName' => $actor->displayName(),
        ];
        $avatar = $actor->avatarUrl();
        if ($avatar !== null) {
            $data['personAvatar'] = $avatar;
        }

        return $data;
    }
}
