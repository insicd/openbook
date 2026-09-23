<?php

namespace App\Application\Services;

use App\Domain\Events\Event;
use App\Domain\Messaging\Conversation;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Mention;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Federation\Delivery\ActivityDelivery;
use App\Federation\Serialization\ActivitySerializer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Invia un messaggio privato 1:1 in una conversazione esistente o nuova.
 */
final class MessageComposer
{
    public function __construct(
        private readonly ConversationResolver $conversations,
        private readonly ConversationReadTracker $readTracker,
        private readonly DirectMessagePolicy $policy,
        private readonly NotificationCreator $notificationCreator,
        private readonly ActivityDelivery $delivery,
    ) {}

    public function send(
        Actor $sender,
        Actor $recipient,
        string $body,
        ?Conversation $conversation = null,
        ?Post $quotedPost = null,
        ?Actor $quotedActor = null,
        ?Event $quotedEvent = null,
    ): Post {
        $body = trim($body);

        if ($quotedPost !== null && $quotedPost->isDirectMessage()) {
            $quotedPost = null;
        }

        if ($quotedActor !== null && (! $quotedActor->isPerson() || ! $quotedActor->isActive())) {
            $quotedActor = null;
        }

        if ($quotedEvent !== null && ! Event::query()
            ->whereKey($quotedEvent->id)
            ->where('status', '!=', Event::STATUS_DELETED)
            ->visibleTo($recipient)
            ->exists()) {
            throw ValidationException::withMessages([
                'quoted_event_id' => [__('openbook.messages.errors.event_unavailable')],
            ]);
        }

        if ($body === '' && $quotedPost === null && $quotedActor === null && $quotedEvent === null) {
            throw ValidationException::withMessages([
                'body' => [__('openbook.messages.errors.empty_body')],
            ]);
        }

        if (! $this->policy->canSend($sender, $recipient)) {
            throw ValidationException::withMessages([
                'body' => [__('openbook.messages.errors.cannot_message')],
            ]);
        }

        $conversation ??= $this->conversations->findOrCreate($sender, $recipient);

        abort_unless($conversation->involves($sender) && $conversation->involves($recipient), 403);

        $post = DB::transaction(function () use ($sender, $recipient, $body, $conversation, $quotedPost, $quotedActor, $quotedEvent) {
            $post = Post::query()->create([
                'actor_id' => $sender->id,
                'body' => $body,
                'visibility' => Post::VISIBILITY_DIRECT,
                'status' => Post::STATUS_PUBLISHED,
                'published_at' => now(),
                'conversation_id' => $conversation->id,
                'quoted_post_id' => $quotedPost?->id,
                'quoted_actor_id' => $quotedActor?->id,
                'quoted_event_id' => $quotedEvent?->id,
            ]);

            Mention::query()->firstOrCreate([
                'mentionable_type' => $post->getMorphClass(),
                'mentionable_id' => $post->id,
                'actor_id' => $recipient->id,
            ]);

            $this->conversations->touch($conversation, $post->published_at);
            $this->readTracker->markRead($conversation, $sender, $post->published_at);

            if ($recipient->isLocal() && $recipient->isPerson()) {
                $this->notificationCreator->notify(
                    $recipient,
                    Notification::TYPE_DIRECT_MESSAGE,
                    $sender,
                    $post,
                );
            }

            return $post;
        });

        if ($sender->isLocal()) {
            $post->load(['mentions.actor', 'quotedPost', 'quotedActor', 'quotedEvent']);
            $this->delivery->deliverContent($post, ActivitySerializer::create($post));
        }

        return $post;
    }
}
