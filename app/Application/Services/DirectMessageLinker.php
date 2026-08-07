<?php

namespace App\Application\Services;

use App\Domain\Messaging\Conversation;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Mention;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Federation\Inbox\RemoteNoteUpserter;
use App\Federation\Serialization\NoteSerializer;

/**
 * Collega un post direct federato alla conversazione 1:1 e notifica il destinatario locale.
 */
final class DirectMessageLinker
{
    public function __construct(
        private readonly ConversationResolver $conversations,
        private readonly NotificationCreator $notificationCreator,
    ) {}

    /**
     * @param  array<string, mixed>  $note
     */
    public function link(Post $post, Actor $author, array $note, bool $wasNew): void
    {
        if ($post->visibility !== Post::VISIBILITY_DIRECT) {
            return;
        }

        $other = $this->resolveOtherParticipant($post, $author, $note);

        if ($other === null || $other->id === $author->id) {
            return;
        }

        $conversation = $this->conversations->findOrCreate($author, $other);

        if ($post->conversation_id !== $conversation->id) {
            $post->update(['conversation_id' => $conversation->id]);
        }

        $this->conversations->touch($conversation, $post->published_at);

        if ($wasNew && $other->isLocal() && $other->isPerson() && $author->id !== $other->id) {
            $this->notificationCreator->notify(
                $other,
                Notification::TYPE_DIRECT_MESSAGE,
                $author,
                $post,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $note
     */
    private function resolveOtherParticipant(Post $post, Actor $author, array $note): ?Actor
    {
        $post->loadMissing('mentions.actor');

        foreach ($post->mentions as $mention) {
            if ($mention->actor !== null && $mention->actor->id !== $author->id) {
                return $mention->actor;
            }
        }

        foreach ($this->audienceUris($note) as $uri) {
            $actor = Actor::query()->where('uri', $uri)->first();

            if ($actor !== null && $actor->id !== $author->id) {
                $this->ensureMention($post, $actor);

                return $actor;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $note
     * @return list<string>
     */
    private function audienceUris(array $note): array
    {
        $uris = [];

        foreach (['to', 'cc'] as $field) {
            $value = $note[$field] ?? null;

            if (is_string($value) && $value !== '') {
                $uris[] = $value;

                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            foreach ($value as $item) {
                if (is_string($item) && $item !== '' && ! $this->isSpecialAudience($item)) {
                    $uris[] = $item;
                }
            }
        }

        return array_values(array_unique($uris));
    }

    private function isSpecialAudience(string $address): bool
    {
        if (in_array($address, [
            NoteSerializer::PUBLIC_STREAM,
            'as:Public',
            'Public',
        ], true)) {
            return true;
        }

        return str_ends_with($address, '/followers');
    }

    private function ensureMention(Post $post, Actor $actor): void
    {
        Mention::query()->firstOrCreate([
            'mentionable_type' => $post->getMorphClass(),
            'mentionable_id' => $post->id,
            'actor_id' => $actor->id,
        ]);
    }
}
