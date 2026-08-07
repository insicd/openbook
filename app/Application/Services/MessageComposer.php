<?php

namespace App\Application\Services;

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
        private readonly DirectMessagePolicy $policy,
        private readonly NotificationCreator $notificationCreator,
        private readonly ActivityDelivery $delivery,
    ) {}

    public function send(Actor $sender, Actor $recipient, string $body, ?Conversation $conversation = null): Post
    {
        $body = trim($body);

        if ($body === '') {
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

        $post = DB::transaction(function () use ($sender, $recipient, $body, $conversation) {
            $post = Post::query()->create([
                'actor_id' => $sender->id,
                'body' => $body,
                'visibility' => Post::VISIBILITY_DIRECT,
                'status' => Post::STATUS_PUBLISHED,
                'published_at' => now(),
                'conversation_id' => $conversation->id,
            ]);

            Mention::query()->firstOrCreate([
                'mentionable_type' => $post->getMorphClass(),
                'mentionable_id' => $post->id,
                'actor_id' => $recipient->id,
            ]);

            $this->conversations->touch($conversation, $post->published_at);

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
            $post->load(['mentions.actor']);
            $this->delivery->deliverContent($post, ActivitySerializer::create($post));
        }

        return $post;
    }
}
