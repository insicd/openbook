<?php

namespace App\Federation\Serialization;

use App\Domain\Events\Event;
use App\Domain\Events\EventComment;
use App\Domain\Posts\Mention;
use App\Domain\Posts\PostBodyRenderer;
use App\Federation\Actors\Actor;
use App\Federation\Actors\LocalActorUrls;

final class EventCommentSerializer
{
    /** @return array<string, mixed> */
    public static function serialize(EventComment $comment): array
    {
        $comment->loadMissing(['actor.endpoints', 'event.actor.endpoints', 'parent.actor', 'mentions.actor', 'media']);
        $actor = $comment->actor;
        $event = $comment->event;
        $target = $comment->parent?->actor ?? $event->actor;
        $followers = $actor->isLocal()
            ? LocalActorUrls::forUsername($actor->preferred_username, $actor->isGroup())['followers']
            : $actor->endpoints?->followers;
        $mentionedUris = $comment->mentions
            ->pluck('actor')
            ->filter()
            ->map(fn (Actor $mentioned): string => $mentioned->activityPubId())
            ->values()
            ->all();
        $directUris = collect([$target?->activityPubId(), ...$mentionedUris])->filter()->unique()->values()->all();
        [$to, $cc] = $event->visibility === Event::VISIBILITY_UNLISTED
            ? [array_values(array_filter([$followers])), array_values(array_unique([NoteSerializer::PUBLIC_STREAM, ...$directUris]))]
            : [[NoteSerializer::PUBLIC_STREAM], array_values(array_unique(array_filter([$followers, ...$directUris])))];
        $content = (string) PostBodyRenderer::renderForFederation($comment->body);

        if ($target !== null && $target->id !== $actor->id && ! str_contains($content, $target->activityPubId())) {
            $content = '<p><a href="'.e($target->activityPubId()).'" class="u-url mention" rel="mention">'.e('@'.$target->handle()).'</a></p>'.$content;
        }

        $note = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $comment->uri,
            'type' => 'Note',
            'attributedTo' => $actor->activityPubId(),
            'inReplyTo' => $comment->parent?->uri ?? $event->uri,
            'content' => $content,
            'url' => $comment->uri,
            'published' => $comment->created_at->toAtomString(),
            'to' => $to,
            'cc' => $cc,
        ];

        if ($comment->edited_at !== null) {
            $note['updated'] = $comment->edited_at->toAtomString();
        }

        $attachments = $comment->media->map(fn ($media): array => [
            'type' => 'Image',
            'mediaType' => $media->mime_type,
            'url' => $media->url(),
            'name' => $media->alt_text ?: '',
        ])->values()->all();

        if ($attachments !== []) {
            $note['attachment'] = $attachments;
        }

        $tags = $comment->mentions
            ->filter(fn (Mention $mention): bool => $mention->actor !== null)
            ->map(fn (Mention $mention): array => [
                'type' => 'Mention',
                'href' => $mention->actor->activityPubId(),
                'name' => '@'.$mention->actor->handle(),
            ]);

        if ($target !== null && $target->id !== $actor->id && ! $tags->contains('href', $target->activityPubId())) {
            $tags->push([
                'type' => 'Mention',
                'href' => $target->activityPubId(),
                'name' => '@'.$target->handle(),
            ]);
        }

        if ($tags->isNotEmpty()) {
            $note['tag'] = $tags->values()->all();
        }

        return $note;
    }

    /** @return array<string, mixed> */
    public static function tombstone(EventComment $comment): array
    {
        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $comment->uri,
            'type' => 'Tombstone',
            'formerType' => 'Note',
            'deleted' => ($comment->edited_at ?? $comment->updated_at)->toAtomString(),
        ];
    }
}
