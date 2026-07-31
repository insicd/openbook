<?php

namespace App\Application\Services;

use App\Domain\Comments\Comment;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\ContentParser;
use App\Domain\Posts\Mention;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Federation\Delivery\ActivityDelivery;
use App\Federation\Serialization\ActivitySerializer;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Crea un commento (di primo livello o risposta annidata) e mantiene
 * coerenti i contatori denormalizzati di post e commento padre. Il limite di
 * profondita' configurato (OPENBOOK_COMMENT_MAX_DEPTH) e' un limite di
 * visualizzazione, non di scrittura: la struttura ad albero reale viene
 * sempre preservata, come richiesto dal design. Se l'autore e' locale, il
 * commento viene anche consegnato come "Create" ai destinatari federati
 * appropriati, con l'autore del post/commento padre come destinatario
 * diretto aggiuntivo (cosi' viene notificato anche se non segue chi risponde).
 */
final class CommentComposer
{
    public function __construct(
        private readonly ContentParser $contentParser,
        private readonly NotificationCreator $notificationCreator,
        private readonly ActivityDelivery $delivery,
    ) {}

    public function compose(Actor $author, Post $post, string $body, ?Comment $parent = null): Comment
    {
        if ($parent !== null && $parent->post_id !== $post->id) {
            throw new InvalidArgumentException('Il commento padre non appartiene a questo post.');
        }

        $comment = DB::transaction(function () use ($author, $post, $body, $parent) {
            $comment = Comment::query()->create([
                'post_id' => $post->id,
                'parent_comment_id' => $parent?->id,
                'actor_id' => $author->id,
                'body' => $body,
                'status' => Comment::STATUS_PUBLISHED,
            ]);

            $post->increment('comments_count');

            if ($parent !== null) {
                $parent->increment('replies_count');
            }

            $this->attachMentions($comment, $author);
            $this->notifyThread($comment, $post, $author, $parent);

            return $comment;
        });

        if ($author->isLocal()) {
            $comment->load('mentions.actor', 'post');
            $repliedToAuthor = $parent !== null ? $parent->actor : $post->actor;

            $this->delivery->deliverContent($comment, ActivitySerializer::create($comment), [$repliedToAuthor]);
        }

        return $comment;
    }

    private function attachMentions(Comment $comment, Actor $author): void
    {
        $mentionedActors = $this->contentParser->extractMentionedActors($comment->body);

        foreach ($mentionedActors as $actor) {
            if ($actor->id === $author->id) {
                continue;
            }

            Mention::query()->create([
                'mentionable_type' => $comment->getMorphClass(),
                'mentionable_id' => $comment->id,
                'actor_id' => $actor->id,
            ]);

            if ($actor->isLocal() && $actor->isPerson()) {
                $this->notificationCreator->notify($actor, Notification::TYPE_MENTION, $author, $comment);
            }
        }
    }

    private function notifyThread(Comment $comment, Post $post, Actor $author, ?Comment $parent): void
    {
        if ($parent !== null) {
            $this->notificationCreator->notify($parent->actor, Notification::TYPE_REPLY, $author, $comment);

            return;
        }

        $this->notificationCreator->notify($post->actor, Notification::TYPE_COMMENT, $author, $comment);
    }
}
