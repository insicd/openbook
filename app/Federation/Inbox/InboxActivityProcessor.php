<?php

namespace App\Federation\Inbox;

use App\Application\Services\AnnounceManager;
use App\Application\Services\FollowManager;
use App\Application\Services\NotificationCreator;
use App\Application\Services\ReactionManager;
use App\Domain\Comments\Comment;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Hashtag;
use App\Domain\Posts\Mention;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Delivery\ActivityDelivery;
use App\Federation\Resolution\ObjectResolver;
use App\Federation\Serialization\ActivitySerializer;
use App\Federation\Serialization\NoteSerializer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;

/**
 * Trasforma un'attivita' ActivityPub verificata (una {@see InboxItem} con
 * firma valida) negli effetti di dominio corrispondenti: nuovi follow,
 * accettazioni, Mi piace, condivisioni, post/commenti remoti in cache,
 * cancellazioni. E' il contro-altare di {@see ActivitySerializer}: dove
 * quello trasforma righe in JSON-LD, questo trasforma JSON-LD in effetti sul
 * database, riusando sempre gli stessi servizi applicativi del percorso
 * locale (FollowManager, ReactionManager, AnnounceManager) cosi' che le due
 * strade restino sempre coerenti.
 *
 * Non lancia mai eccezioni per attivita' "non applicabili" (destinatario
 * sconosciuto, oggetto non risolvibile, tipo non supportato): restituisce
 * semplicemente {@see InboxItem::STATUS_IGNORED}. Le eccezioni vere e proprie
 * (errori di database, bug) risalgono al chiamante, che le registra come
 * fallimento del job e lascia alla coda il compito di ritentare.
 */
final class InboxActivityProcessor
{
    public function __construct(
        private readonly ObjectResolver $objects,
        private readonly ActivityDelivery $delivery,
        private readonly FollowManager $followManager,
        private readonly ReactionManager $reactionManager,
        private readonly AnnounceManager $announceManager,
        private readonly NotificationCreator $notificationCreator,
    ) {}

    public function process(InboxItem $item): string
    {
        try {
            $activity = json_decode($item->payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return InboxItem::STATUS_IGNORED;
        }

        if (! is_array($activity)) {
            return InboxItem::STATUS_IGNORED;
        }

        $signer = $this->objects->resolveActor($item->actor_uri);

        if ($signer === null || $signer->isLocal()) {
            return InboxItem::STATUS_IGNORED;
        }

        return match ($item->activity_type) {
            'Follow' => $this->handleFollow($activity, $signer),
            'Accept' => $this->handleAccept($activity, $signer),
            'Reject' => $this->handleReject($activity, $signer),
            'Undo' => $this->handleUndo($activity, $signer),
            'Create' => $this->handleCreateOrUpdate($activity, $signer, isUpdate: false),
            'Update' => $this->handleCreateOrUpdate($activity, $signer, isUpdate: true),
            'Delete' => $this->handleDelete($activity, $signer),
            'Like' => $this->handleLike($activity, $signer),
            'Announce' => $this->handleAnnounce($activity, $signer),
            default => InboxItem::STATUS_IGNORED,
        };
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    private function handleFollow(array $activity, Actor $follower): string
    {
        $target = $this->objects->resolveActor($this->objectId($activity['object'] ?? null) ?? '');

        if ($target === null || ! $target->isLocal() || ! $target->isActive()) {
            return InboxItem::STATUS_IGNORED;
        }

        try {
            $follow = $this->followManager->follow($follower, $target);
        } catch (InvalidArgumentException) {
            return InboxItem::STATUS_IGNORED;
        }

        $follow->forceFill(['remote_activity_uri' => (string) $activity['id']])->save();
        $follow->setRelation('follower', $follower);
        $follow->setRelation('following', $target);

        if ($follow->isAccepted()) {
            $this->delivery->deliverTo($target, $follower, ActivitySerializer::accept($follow));
        }

        return InboxItem::STATUS_PROCESSED;
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    private function handleAccept(array $activity, Actor $remoteTarget): string
    {
        $follow = $this->resolveOutgoingFollow($activity['object'] ?? null, $remoteTarget);

        if ($follow === null) {
            return InboxItem::STATUS_IGNORED;
        }

        try {
            $this->followManager->accept($remoteTarget, $follow->follower);
        } catch (ModelNotFoundException) {
            return InboxItem::STATUS_IGNORED;
        }

        return InboxItem::STATUS_PROCESSED;
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    private function handleReject(array $activity, Actor $remoteTarget): string
    {
        $follow = $this->resolveOutgoingFollow($activity['object'] ?? null, $remoteTarget);

        if ($follow === null) {
            return InboxItem::STATUS_IGNORED;
        }

        try {
            $this->followManager->reject($remoteTarget, $follow->follower);
        } catch (ModelNotFoundException) {
            return InboxItem::STATUS_IGNORED;
        }

        return InboxItem::STATUS_PROCESSED;
    }

    /**
     * Risolve un Follow *originato da questa istanza* (verso un target
     * remoto) a partire dall'"object" di un Accept/Reject: prova prima con
     * l'oggetto incorporato (actor/object espliciti), poi con l'id
     * dell'attivita' Follow originale che avevamo derivato noi stessi
     * (formato "/activities/follows/{uuid}").
     */
    private function resolveOutgoingFollow(mixed $object, Actor $remoteTarget): ?Follow
    {
        if (is_array($object) && isset($object['actor'])) {
            $followerUri = is_string($object['actor']) ? $object['actor'] : null;
            $follower = $followerUri !== null ? Actor::query()->where('uri', $followerUri)->where('is_local', true)->first() : null;

            if ($follower !== null) {
                return Follow::query()
                    ->where('follower_id', $follower->id)
                    ->where('following_id', $remoteTarget->id)
                    ->first();
            }
        }

        $objectId = $this->objectId($object);

        if ($objectId !== null && preg_match('#/activities/follows/([0-9a-fA-F-]{36})$#', $objectId, $matches) === 1) {
            return Follow::query()
                ->where('id', $matches[1])
                ->where('following_id', $remoteTarget->id)
                ->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    private function handleUndo(array $activity, Actor $actor): string
    {
        $object = $activity['object'] ?? null;
        $type = is_array($object) ? ($object['type'] ?? null) : null;

        return match ($type) {
            'Follow' => $this->handleUndoFollow($object, $actor),
            'Like' => $this->handleUndoLike($object, $actor),
            'Announce' => $this->handleUndoAnnounce($object, $actor),
            default => $this->handleUndoByReference($object, $actor),
        };
    }

    /**
     * Un Undo il cui "object" e' solo un id (senza tipo incorporato): l'unico
     * caso che possiamo comunque gestire con i dati gia' disponibili e' un
     * Undo(Follow) in cui l'id corrisponde a quello che avevamo registrato
     * per la richiesta di follow remota in ingresso.
     */
    private function handleUndoByReference(mixed $object, Actor $actor): string
    {
        $objectId = $this->objectId($object);

        if ($objectId === null) {
            return InboxItem::STATUS_IGNORED;
        }

        $follow = Follow::query()
            ->where('follower_id', $actor->id)
            ->where('remote_activity_uri', $objectId)
            ->first();

        if ($follow === null) {
            return InboxItem::STATUS_IGNORED;
        }

        $this->followManager->unfollow($actor, $follow->following);

        return InboxItem::STATUS_PROCESSED;
    }

    /**
     * @param  array<string, mixed>  $embeddedFollow
     */
    private function handleUndoFollow(array $embeddedFollow, Actor $actor): string
    {
        $targetUri = $this->objectId($embeddedFollow['object'] ?? null);
        $target = $targetUri !== null ? Actor::query()->where('uri', $targetUri)->first() : null;

        if ($target === null) {
            return InboxItem::STATUS_IGNORED;
        }

        $this->followManager->unfollow($actor, $target);

        return InboxItem::STATUS_PROCESSED;
    }

    /**
     * @param  array<string, mixed>  $embeddedLike
     */
    private function handleUndoLike(array $embeddedLike, Actor $actor): string
    {
        $targetUri = $this->objectId($embeddedLike['object'] ?? null);
        $target = $targetUri !== null ? $this->objects->resolvePostOrComment($targetUri) : null;

        if ($target === null || $target->actor === null || ! $target->actor->isLocal()) {
            return InboxItem::STATUS_IGNORED;
        }

        $this->reactionManager->unlike($actor, $target);

        return InboxItem::STATUS_PROCESSED;
    }

    /**
     * @param  array<string, mixed>  $embeddedAnnounce
     */
    private function handleUndoAnnounce(array $embeddedAnnounce, Actor $actor): string
    {
        $targetUri = $this->objectId($embeddedAnnounce['object'] ?? null);
        $post = $targetUri !== null ? $this->objects->resolvePost($targetUri) : null;

        if ($post === null || ! $post->actor->isLocal()) {
            return InboxItem::STATUS_IGNORED;
        }

        $this->announceManager->unannounce($actor, $post);

        return InboxItem::STATUS_PROCESSED;
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    private function handleLike(array $activity, Actor $actor): string
    {
        $targetUri = $this->objectId($activity['object'] ?? null);
        $target = $targetUri !== null ? $this->objects->resolvePostOrComment($targetUri) : null;

        if ($target === null || $target->actor === null || ! $target->actor->isLocal()) {
            return InboxItem::STATUS_IGNORED;
        }

        $this->reactionManager->like($actor, $target);

        return InboxItem::STATUS_PROCESSED;
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    private function handleAnnounce(array $activity, Actor $actor): string
    {
        $targetUri = $this->objectId($activity['object'] ?? null);
        $post = $targetUri !== null ? $this->objects->resolvePost($targetUri) : null;

        if ($post === null || ! $post->actor->isLocal()) {
            return InboxItem::STATUS_IGNORED;
        }

        $this->announceManager->announce($actor, $post);

        return InboxItem::STATUS_PROCESSED;
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    private function handleDelete(array $activity, Actor $actor): string
    {
        $objectId = $this->objectId($activity['object'] ?? null);

        if ($objectId === null) {
            return InboxItem::STATUS_IGNORED;
        }

        $target = $this->objects->resolvePostOrComment($objectId);

        if ($target === null || $target->actor_id !== $actor->id) {
            // Sconosciuto localmente, oppure l'autore non coincide con chi
            // firma la richiesta: in entrambi i casi non c'e' nulla da fare.
            return InboxItem::STATUS_IGNORED;
        }

        if ($target instanceof Post) {
            $target->update(['title' => null, 'content_warning' => null, 'body' => '', 'status' => Post::STATUS_DELETED]);
        } else {
            $target->update(['body' => '', 'status' => Comment::STATUS_DELETED]);
        }

        return InboxItem::STATUS_PROCESSED;
    }

    /**
     * Gestisce sia "Create" sia "Update": entrambi trasportano la stessa
     * rappresentazione "Note" completa nell'"object" e differiscono solo per
     * l'eventuale presenza di una riga gia' esistente (identificata dal suo
     * "uri"). Attivita' non incorporate (solo un id remoto da recuperare) non
     * sono supportate in questa fase: vengono ignorate.
     *
     * @param  array<string, mixed>  $activity
     */
    private function handleCreateOrUpdate(array $activity, Actor $actor, bool $isUpdate): string
    {
        $note = $activity['object'] ?? null;

        if (! is_array($note) || ($note['type'] ?? null) !== 'Note') {
            return InboxItem::STATUS_IGNORED;
        }

        $noteUri = $note['id'] ?? null;
        $attributedTo = $note['attributedTo'] ?? null;

        if (! is_string($noteUri) || $noteUri === '' || $attributedTo !== $actor->uri) {
            // L'attore che firma deve coincidere con l'autore dichiarato
            // della Note: impedisce di spacciare contenuto per conto altrui.
            return InboxItem::STATUS_IGNORED;
        }

        $inReplyTo = is_string($note['inReplyTo'] ?? null) ? $note['inReplyTo'] : null;
        $parentPost = null;
        $parentComment = null;

        if ($inReplyTo !== null) {
            $parentComment = $this->objects->resolveComment($inReplyTo);
            $parentPost = $parentComment !== null ? null : $this->objects->resolvePost($inReplyTo);

            if ($parentComment === null && $parentPost === null) {
                // Risposta a un contenuto che non conosciamo: non possiamo
                // agganciarla a nulla di locale, quindi la ignoriamo.
                return InboxItem::STATUS_IGNORED;
            }
        }

        if (! $this->isRelevant($actor, $note, $parentPost, $parentComment)) {
            return InboxItem::STATUS_IGNORED;
        }

        $body = RemoteContentSanitizer::toPlainText((string) ($note['content'] ?? ''));
        $publishedAt = isset($note['published']) && is_string($note['published'])
            ? Carbon::parse($note['published'])
            : now();

        $isReply = $parentPost !== null || $parentComment !== null;

        return DB::transaction(function () use ($isReply, $note, $noteUri, $actor, $body, $publishedAt, $parentPost, $parentComment) {
            if ($isReply) {
                return $this->upsertRemoteComment($note, $noteUri, $actor, $body, $parentPost, $parentComment);
            }

            return $this->upsertRemotePost($note, $noteUri, $actor, $body, $publishedAt);
        });
    }

    /**
     * @param  array<string, mixed>  $note
     */
    private function upsertRemotePost(array $note, string $noteUri, Actor $actor, string $body, Carbon $publishedAt): string
    {
        $sensitive = (bool) ($note['sensitive'] ?? false);

        /** @var Post $post */
        $post = Post::query()->where('uri', $noteUri)->first() ?? new Post(['uri' => $noteUri]);
        $wasNew = ! $post->exists;

        $post->fill([
            'actor_id' => $actor->id,
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
            $this->attachTags($post, $note);
        }

        return InboxItem::STATUS_PROCESSED;
    }

    /**
     * @param  array<string, mixed>  $note
     */
    private function upsertRemoteComment(array $note, string $noteUri, Actor $actor, string $body, ?Post $parentPost, ?Comment $parentComment): string
    {
        $postId = $parentComment?->post_id ?? $parentPost?->id;

        if ($postId === null) {
            return InboxItem::STATUS_IGNORED;
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
                $this->notificationCreator->notify($parentComment->actor, Notification::TYPE_REPLY, $actor, $comment);
            } elseif ($parentPost !== null) {
                $parentPost->increment('comments_count');
                $this->notificationCreator->notify($parentPost->actor, Notification::TYPE_COMMENT, $actor, $comment);
            }

            $this->attachTags($comment, $note);
        }

        return InboxItem::STATUS_PROCESSED;
    }

    /**
     * Un Create/Update remoto viene accettato solo se riguarda questa
     * istanza in modo concreto: l'autore e' seguito da un attore locale, la
     * Note e' una risposta a qualcosa che gia' conosciamo, oppure menziona
     * esplicitamente un attore locale. In caso contrario non ha nessun posto
     * dove comparire e verrebbe solo occupare spazio inutilmente.
     *
     * @param  array<string, mixed>  $note
     */
    private function isRelevant(Actor $author, array $note, ?Post $parentPost, ?Comment $parentComment): bool
    {
        if ($parentPost !== null || $parentComment !== null) {
            return true;
        }

        $hasLocalFollower = DB::table('follows')
            ->where('following_id', $author->id)
            ->where('status', 'accepted')
            ->whereIn('follower_id', function ($query) {
                $query->select('id')->from('actors')->where('is_local', true);
            })
            ->exists();

        if ($hasLocalFollower) {
            return true;
        }

        return $this->mentionsLocalActor($note);
    }

    /**
     * @param  array<string, mixed>  $note
     */
    private function mentionsLocalActor(array $note): bool
    {
        $tags = is_array($note['tag'] ?? null) ? $note['tag'] : [];

        foreach ($tags as $tag) {
            if (! is_array($tag) || ($tag['type'] ?? null) !== 'Mention' || ! is_string($tag['href'] ?? null)) {
                continue;
            }

            if (Actor::query()->where('uri', $tag['href'])->where('is_local', true)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $note
     */
    private function attachTags(Post|Comment $target, array $note): void
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

                $this->notificationCreator->notify($mentionedActor, Notification::TYPE_MENTION, $target->actor, $target);
            }
        }
    }

    /**
     * Deduce la visibilita' locale a partire dagli indirizzi "to"/"cc" di una
     * Note remota, rispecchiando la logica inversa di
     * {@see NoteSerializer}.
     *
     * @param  array<string, mixed>  $note
     */
    private function visibilityFromAudience(array $note): string
    {
        $to = is_array($note['to'] ?? null) ? $note['to'] : [];
        $cc = is_array($note['cc'] ?? null) ? $note['cc'] : [];
        $public = NoteSerializer::PUBLIC_STREAM;

        if (in_array($public, $to, true)) {
            return Post::VISIBILITY_PUBLIC;
        }

        if (in_array($public, $cc, true)) {
            return Post::VISIBILITY_UNLISTED;
        }

        if ($to === [] && $cc === []) {
            return Post::VISIBILITY_DIRECT;
        }

        return Post::VISIBILITY_FOLLOWERS;
    }

    /**
     * Estrae l'id da un "object" che, secondo la specifica ActivityStreams,
     * puo' indifferentemente essere una stringa (il solo id) o un oggetto
     * incorporato con un campo "id".
     */
    private function objectId(mixed $object): ?string
    {
        if (is_string($object) && $object !== '') {
            return $object;
        }

        if (is_array($object) && isset($object['id']) && is_string($object['id'])) {
            return $object['id'];
        }

        return null;
    }
}
