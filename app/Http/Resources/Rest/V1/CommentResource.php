<?php

namespace App\Http\Resources\Rest\V1;

use App\Federation\Serialization\NoteSerializer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommentResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $comment = $this->resource;
        $data = [
            'commentId' => $comment->id,
            'commentUri' => NoteSerializer::commentUri($comment),
            'commentPostId' => $comment->post_id,
            'commentParentId' => $comment->parent_comment_id,
            'commentAuthor' => (new PersonReferenceResource($comment->actor))->resolve($request),
            'commentContent' => $comment->body,
            'commentPublishedAt' => $comment->created_at->toISOString(),
            'commentCounters' => [
                'commentLikesCount' => $comment->likes_count,
                'commentRepliesCount' => $comment->replies_count,
            ],
            'commentMyInteractions' => ['commentLiked' => (bool) $comment->liked_by_viewer],
        ];
        if ($comment->edited_at !== null) {
            $data['commentEditedAt'] = $comment->edited_at->toISOString();
        }
        if (filled($comment->language)) {
            $data['commentLanguage'] = $comment->language;
        }
        if (! empty($comment->custom_emojis)) {
            $data['commentCustomEmojis'] = (object) $comment->custom_emojis;
        }
        if ($comment->media->isNotEmpty()) {
            $data['commentAttachments'] = MediaResource::collection($comment->media)->resolve($request);
        }

        return $data;
    }
}
