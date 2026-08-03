<?php

namespace App\Federation\Inbox;

use App\Application\Services\NotificationCreator;
use App\Domain\Comments\Comment;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Hashtag;
use App\Domain\Posts\Mention;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Federation\Outbox\RemoteOutboxFetcher;
use App\Federation\Replies\RemoteRepliesFetcher;
use App\Federation\Serialization\NoteSerializer;
use Illuminate\Support\Carbon;

/**
 * Salva (creando o aggiornando) la rappresentazione locale di una Note o
 * Page remota, in cache come {@see Post} o {@see Comment}: logica condivisa
 * da {@see InboxActivityProcessor}, {@see RemoteOutboxFetcher} e
 * {@see RemoteRepliesFetcher}, cosi' che le strade producano sempre lo
 * stesso risultato. Lemmy usa "Page" per i post di community; i commenti
 * restano "Note".
 */
final class RemoteNoteUpserter
{
    public function __construct(
        private readonly NotificationCreator $notificationCreator,
        private readonly RemoteAttachmentIngester $attachments,
    ) {}

    /**
     * @param  array<string, mixed>  $note
     */
    public function upsertPost(array $note, string $noteUri, Actor $actor, string $body, Carbon $publishedAt, bool $notifyMentions = true): Post
    {
        $sensitive = (bool) ($note['sensitive'] ?? false);

        /** @var Post $post */
        $post = Post::query()->where('uri', $noteUri)->first() ?? new Post(['uri' => $noteUri]);
        $wasNew = ! $post->exists;

        $post->fill([
            'actor_id' => $actor->id,
            'title' => RemotePostObject::title($note),
            'content_warning' => $sensitive ? (is_string($note['summary'] ?? null) ? mb_substr($note['summary'], 0, 255) : null) : null,
            'body' => $body,
            'visibility' => $this->visibilityFromAudience($note),
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => $publishedAt,
        ]);

        if (! $wasNew) {
            $post->edited_at = now();
        }

        $post->save();

        if ($wasNew) {
            $this->attachTags($post, $note, $notifyMentions);
        }

        $this->attachments->sync($post, $actor, $note);

        return $post;
    }

    /**
     * @param  array<string, mixed>  $note
     */
    public function upsertComment(array $note, string $noteUri, Actor $actor, string $body, ?Post $parentPost, ?Comment $parentComment, bool $notifyMentions = true): ?Comment
    {
        $postId = $parentComment?->post_id ?? $parentPost?->id;

        if ($postId === null) {
            return null;
        }

        /** @var Comment $comment */
        $comment = Comment::query()->where('uri', $noteUri)->first() ?? new Comment(['uri' => $noteUri]);
        $wasNew = ! $comment->exists;

        $comment->fill([
            'post_id' => $postId,
            'parent_comment_id' => $parentComment?->id,
            'actor_id' => $actor->id,
            'body' => $body,
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        if (! $wasNew) {
            $comment->edited_at = now();
        }

        $comment->save();

        if ($wasNew) {
            if ($parentComment !== null) {
                $parentComment->increment('replies_count');
                if ($notifyMentions) {
                    $this->notificationCreator->notify($parentComment->actor, Notification::TYPE_REPLY, $actor, $comment);
                }
            } elseif ($parentPost !== null) {
                $parentPost->increment('comments_count');
                if ($notifyMentions) {
                    $this->notificationCreator->notify($parentPost->actor, Notification::TYPE_COMMENT, $actor, $comment);
                }
            }

            $this->attachTags($comment, $note, $notifyMentions);
        }

        $this->attachments->sync($comment, $actor, $note);

        return $comment;
    }

    /**
     * Deduce la visibilita' locale a partire dagli indirizzi "to"/"cc" di una
     * Note remota, rispecchiando la logica inversa di {@see NoteSerializer}.
     *
     * ActivityStreams consente valori singoli al posto di array: GoToSocial
     * (e altri) spesso inviano `"to": "https://www.w3.org/ns/activitystreams#Public"`
     * come stringa. Se non li normalizziamo, una Note pubblica viene trattata
     * come diretta e scartata dai fetch di replies/outbox.
     *
     * @param  array<string, mixed>  $note
     */
    public function visibilityFromAudience(array $note): string
    {
        $to = $this->audienceList($note['to'] ?? null);
        $cc = $this->audienceList($note['cc'] ?? null);

        if ($this->addressesPublic($to)) {
            return Post::VISIBILITY_PUBLIC;
        }

        if ($this->addressesPublic($cc)) {
            return Post::VISIBILITY_UNLISTED;
        }

        if ($to === [] && $cc === []) {
            return Post::VISIBILITY_DIRECT;
        }

        return Post::VISIBILITY_FOLLOWERS;
    }

    /**
     * @return list<string>
     */
    private function audienceList(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $addresses = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $addresses[] = $item;
            }
        }

        return $addresses;
    }

    /**
     * @param  list<string>  $addresses
     */
    private function addressesPublic(array $addresses): bool
    {
        foreach ($addresses as $address) {
            if (in_array($address, [
                NoteSerializer::PUBLIC_STREAM,
                'as:Public',
                'Public',
            ], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hashtag e menzioni incorporati nella Note ("tag"): le menzioni di un
     * Actor locale generano anche una notifica, a meno che non si tratti di
     * un recupero massivo di contenuti passati (vedi "$notifyMentions"), nel
     * qual caso notificare oggi una menzione di settimane fa creerebbe solo
     * confusione.
     *
     * @param  array<string, mixed>  $note
     */
    public function attachTags(Post|Comment $target, array $note, bool $notifyMentions = true): void
    {
        $tags = is_array($note['tag'] ?? null) ? $note['tag'] : [];

        foreach ($tags as $tag) {
            if (! is_array($tag)) {
                continue;
            }

            if ($target instanceof Post && ($tag['type'] ?? null) === 'Hashtag' && is_string($tag['name'] ?? null)) {
                $hashtag = Hashtag::query()->firstOrCreate(['name' => Hashtag::normalize($tag['name'])]);
                $target->hashtags()->syncWithoutDetaching([$hashtag->id]);

                continue;
            }

            if (($tag['type'] ?? null) === 'Mention' && is_string($tag['href'] ?? null)) {
                $mentionedActor = Actor::query()->where('uri', $tag['href'])->where('is_local', true)->first();

                if ($mentionedActor === null) {
                    continue;
                }

                Mention::query()->create([
                    'mentionable_type' => $target->getMorphClass(),
                    'mentionable_id' => $target->id,
                    'actor_id' => $mentionedActor->id,
                ]);

                if ($notifyMentions) {
                    $this->notificationCreator->notify($mentionedActor, Notification::TYPE_MENTION, $target->actor, $target);
                }
            }
        }
    }
}
