<?php

namespace App\Application\Services;

use App\Domain\Comments\Comment;
use App\Domain\Events\Event;
use App\Domain\Events\EventComment;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Federation\Delivery\ActivityDelivery;
use App\Federation\Serialization\ActivitySerializer;
use Illuminate\Support\Facades\DB;

/** Shared deletion flow; callers authorize the initiating user before invoking it. */
final class LocalContentDeleter
{
    public function __construct(
        private readonly CommentSoftDeleter $comments,
        private readonly EventCommentSoftDeleter $eventComments,
        private readonly EventComposer $events,
        private readonly ActivityDelivery $delivery,
    ) {}

    public function delete(Actor $initiator, Post|Comment|Event|EventComment $content): bool
    {
        return DB::transaction(function () use ($initiator, $content): bool {
            $content = $content->newQuery()->whereKey($content->id)->lockForUpdate()->first();
            if ($content === null || $content->isRemote() || $content->status === 'deleted') {
                return false;
            }
            $content->load('actor', 'mentions.actor');
            if (! $content->actor?->isLocal()) {
                return false;
            }
            if ($content instanceof Event) {
                $this->events->delete($initiator, $content);

                return true;
            }
            if (! $content->isPublished()) {
                return false;
            }
            $directTargets = [];
            if ($content instanceof Post) {
                $content->update(['title' => null, 'content_warning' => null, 'body' => '', 'status' => Post::STATUS_DELETED]);
                $content->location()->delete();
            } elseif ($content instanceof Comment) {
                $content->load('post.actor', 'parent.actor');
                $directTargets = array_filter([$content->parent?->actor ?? $content->post?->actor]);
                $this->comments->delete($content);
            } else {
                $content->load('event.actor', 'parent.actor');
                $directTargets = $content->mentions->pluck('actor')
                    ->push($content->parent?->actor ?? $content->event?->actor)
                    ->filter()->unique('id')->values()->all();
                $this->eventComments->delete($content);
            }
            $this->delivery->deliverContent($content, ActivitySerializer::delete($content), $directTargets);

            return true;
        });
    }
}
