<?php

namespace App\Http\Resources\Rest\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MediaResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'mediaId' => $this->resource->id,
            'mediaUrl' => url($this->resource->url()),
            'mediaPreviewUrl' => url($this->resource->thumbnailUrl()),
            'mediaMimeType' => $this->resource->mime_type,
            'mediaWidth' => $this->resource->width,
            'mediaHeight' => $this->resource->height,
            'mediaAltText' => $this->resource->alt_text,
        ];
    }
}
