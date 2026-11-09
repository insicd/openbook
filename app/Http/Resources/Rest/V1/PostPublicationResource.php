<?php

namespace App\Http\Resources\Rest\V1;

use App\Domain\Posts\PendingPostPublication;
use App\Domain\Posts\Post;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PostPublicationResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $post = $this->resource->post;
        $available = $this->resource->status === PendingPostPublication::STATUS_PUBLISHED
            && $post !== null && $post->status === Post::STATUS_PUBLISHED;

        return [
            'postPublicationId' => $this->resource->id,
            'postPublicationStatus' => $this->resource->status,
            'postId' => $available ? $post->id : null,
        ];
    }
}
