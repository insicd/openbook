<?php

namespace App\Http\Resources\Rest\V1;

use App\Federation\Serialization\NoteSerializer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $post = $this->resource;
        $warning = $post->effectiveContentWarning();
        $data = [
            'postId' => $post->id,
            'postUri' => NoteSerializer::postUri($post),
            'postAuthor' => (new PersonReferenceResource($post->actor))->resolve($request),
            'postContent' => $post->body,
            'postCW' => $warning !== null,
            'postVisibility' => $post->visibility,
            'postPublishedAt' => $post->published_at->toISOString(),
            'postAttributes' => ['postLocation' => $post->location === null ? null : (new LocationResource($post->location))->resolve($request)],
            'postCounters' => [
                'postLikesCount' => $post->likes_count,
                'postCommentsCount' => $post->comments_count,
                'postAnnouncesCount' => $post->announces_count,
            ],
            'postMyInteractions' => [
                'postLiked' => (bool) $post->liked_by_viewer,
                'postAnnounced' => (bool) $post->announced_by_viewer,
                'postDirectAnnounced' => (bool) $post->direct_announced_by_viewer,
            ],
        ];
        foreach (['postName' => $post->title, 'postSourceUri' => $post->source_uri,
            'postLanguage' => $post->language, 'postCWText' => $warning] as $key => $value) {
            if (filled($value)) {
                $data[$key] = $value;
            }
        }
        if ($post->edited_at !== null) {
            $data['postEditedAt'] = $post->edited_at->toISOString();
        }
        if (! empty($post->custom_emojis)) {
            $data['postCustomEmojis'] = (object) $post->custom_emojis;
        }
        if ($post->media->isNotEmpty()) {
            $data['postAttachments'] = MediaResource::collection($post->media)->resolve($request);
        }

        return $data;
    }
}
