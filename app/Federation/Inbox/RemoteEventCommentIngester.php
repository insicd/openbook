<?php

namespace App\Federation\Inbox;

use App\Domain\Events\Event;
use App\Domain\Events\EventComment;
use App\Federation\Actors\Actor;
use App\Federation\Support\ActivityPubTimestamp;
use App\Infrastructure\Media\Media;

final class RemoteEventCommentIngester
{
    public function __construct(
        private readonly RemoteAttachmentIngester $attachments,
    ) {}

    /** @param array<string, mixed> $note */
    public function ingest(
        array $note,
        string $noteUri,
        Actor $author,
        string $body,
        Event $event,
        ?EventComment $parent = null,
    ): EventComment {
        /** @var EventComment $comment */
        $comment = EventComment::query()->where('uri', $noteUri)->first() ?? new EventComment(['uri' => $noteUri]);
        $wasNew = ! $comment->exists;

        // Una tombstone federata è definitiva: Update/Create tardivi non
        // devono far riapparire un commento già eliminato.
        if (! $wasNew && ! $comment->isPublished()) {
            return $comment;
        }

        $published = $note['published'] ?? null;

        if ($wasNew && is_string($published) && $published !== '') {
            $comment->created_at = ActivityPubTimestamp::parse($published);
        }

        $comment->fill([
            'event_id' => $event->id,
            'parent_event_comment_id' => $parent?->id,
            'actor_id' => $author->id,
            'body' => $body,
            'custom_emojis' => RemoteCustomEmoji::extract($note) ?: null,
            'status' => EventComment::STATUS_PUBLISHED,
        ]);

        if (! $wasNew) {
            $comment->edited_at = now();
        }

        $comment->save();
        $this->attachments->sync($comment, $author, $note);

        return $comment;
    }

    public function delete(EventComment $comment): void
    {
        $mediaIds = $comment->media()->pluck('media.id');
        $comment->media()->detach();

        if ($mediaIds->isNotEmpty()) {
            Media::query()
                ->whereIn('id', $mediaIds)
                ->whereNotNull('remote_url')
                ->whereDoesntHave('posts')
                ->whereDoesntHave('comments')
                ->whereDoesntHave('events')
                ->whereDoesntHave('eventComments')
                ->delete();
        }

        $comment->forceFill([
            'body' => '',
            'custom_emojis' => null,
            'status' => EventComment::STATUS_DELETED,
            'edited_at' => now(),
        ])->save();
    }
}
