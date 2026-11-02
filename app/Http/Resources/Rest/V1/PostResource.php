<?php

namespace App\Http\Resources\Rest\V1;

use App\Federation\Serialization\NoteSerializer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostResource extends JsonResource
{
    public static $wrap = null;

    private bool $timelineContext = false;

    private bool $useShareSort = true;

    public function withTimelineContext(bool $useShareSort = true): self
    {
        $this->timelineContext = true;
        $this->useShareSort = $useShareSort;

        return $this;
    }

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
        if ($this->timelineContext) {
            $sortAt = $this->useShareSort ? ($post->shared_at ?? $post->published_at) : $post->published_at;
            $data['postTimelineAt'] = $sortAt->toISOString();
        }
        if ($post->shared_at !== null) {
            $sharer = $post->sharedBy ?? ($post->shared_by_actor_id === $post->actor_id ? $post->actor : null);
            if ($sharer !== null) {
                $data['postAnnounce'] = [
                    'announceAuthor' => (new PersonReferenceResource($sharer))->resolve($request),
                    'announcePublishedAt' => $post->shared_at->toISOString(),
                ];
            }
        }

        return $data;
    }
}
