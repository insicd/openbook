<?php

namespace App\Application\Services;

use App\Domain\Events\Event;
use App\Domain\Events\EventComment;
use App\Domain\Events\EventCommentAttachment;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\ContentParser;
use App\Domain\Posts\Mention;
use App\Federation\Actors\Actor;
use App\Federation\Delivery\ActivityDelivery;
use App\Federation\Serialization\ActivitySerializer;
use App\Infrastructure\Media\MediaUploader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class EventCommentComposer
{
    public function __construct(
        private readonly ContentParser $contentParser,
        private readonly NotificationCreator $notifications,
        private readonly ActivityDelivery $delivery,
        private readonly MediaUploader $mediaUploader,
    ) {}

    /**
     * @param  array<int, UploadedFile>  $images
     * @param  array<int, string|null>  $altTexts
     */
    public function compose(
        Actor $author,
        Event $event,
        string $body,
        ?EventComment $parent = null,
        array $images = [],
        array $altTexts = [],
    ): EventComment {
        if (! $event->isOpenForInteractions()) {
            throw new InvalidArgumentException('Questo evento non accetta più commenti.');
        }

        if ($parent !== null && $parent->event_id !== $event->id) {
            throw new InvalidArgumentException('Il commento padre non appartiene a questo evento.');
        }

        $maxAttachments = (int) config('openbook.media.max_attachments_per_post');

        if (count($images) > $maxAttachments) {
            throw new InvalidArgumentException("Puoi allegare al massimo {$maxAttachments} file per commento.");
        }

        $comment = DB::transaction(function () use ($author, $event, $body, $parent, $images, $altTexts): EventComment {
            $comment = new EventComment;
            $comment->id = $comment->newUniqueId();
            $comment->forceFill([
                'event_id' => $event->id,
                'parent_event_comment_id' => $parent?->id,
                'actor_id' => $author->id,
                'uri' => route('event-comments.show', $comment->id),
                'body' => $body,
                'status' => EventComment::STATUS_PUBLISHED,
            ])->save();

            foreach (array_values($images) as $position => $image) {
                $media = $this->mediaUploader->store($image, $author, $altTexts[$position] ?? null);
                EventCommentAttachment::query()->create([
                    'event_comment_id' => $comment->id,
                    'media_id' => $media->id,
                    'position' => $position,
                ]);
            }

            $target = $parent?->actor ?? $event->actor;
            $this->notifications->notify(
                $target,
                $parent !== null ? Notification::TYPE_REPLY : Notification::TYPE_COMMENT,
                $author,
                $comment,
            );
            $this->attachMentions($comment, $author);

            return $comment;
        });

        if ($author->isLocal()) {
            $comment->load('mentions.actor', 'event.actor.endpoints', 'parent.actor', 'media');
            $this->delivery->deliverContent(
                $comment,
                ActivitySerializer::create($comment),
                [$parent?->actor ?? $event->actor],
            );
        }

        return $comment;
    }

    private function attachMentions(EventComment $comment, Actor $author): void
    {
        foreach ($this->contentParser->extractMentionedActors($comment->body) as $actor) {
            if ($actor->id === $author->id) {
                continue;
            }

            Mention::query()->create([
                'mentionable_type' => $comment->getMorphClass(),
                'mentionable_id' => $comment->id,
                'actor_id' => $actor->id,
            ]);

            if ($actor->isLocal() && $actor->isPerson()) {
                $this->notifications->notify($actor, Notification::TYPE_MENTION, $author, $comment);
            }
        }
    }
}
