<?php

namespace App\Federation\Inbox;

use App\Application\Services\AnnounceManager;
use App\Application\Services\CommentSoftDeleter;
use App\Application\Services\FollowManager;
use App\Application\Services\ReactionManager;
use App\Domain\Comments\Comment;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Actors\RemoteActorResolver;
use App\Federation\Delivery\ActivityDelivery;
use App\Federation\Resolution\ObjectResolver;
use App\Federation\Serialization\ActivitySerializer;
use App\Federation\Serialization\NoteSerializer;
use App\Federation\Support\ActivityPubTimestamp;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
        private readonly RemoteActorResolver $remoteActorResolver,
        private readonly RemoteNoteUpserter $noteUpserter,
        private readonly RemoteNoteDocumentFetcher $noteDocumentFetcher,
        private readonly CommentSoftDeleter $commentSoftDeleter,
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
            'Create' => $this->handleCreateOrUpdate($activity, $signer),
            'Update' => $this->handleUpdate($activity, $signer),
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
     * remoto) a partire dall'"object" di un Accept/Reject.
     *
     * Lemmy e altri server possono (a) percent-encodare gli URI (`%40` al
     * posto di `@`), (b) annidare l'actor come oggetto `{id}`, (c) riferire
     * il Follow solo per id. Si prova nell'ordine: follower locale + target,
     * id dell'attivita' Follow, infine unico pending verso quel target.
     */
    private function resolveOutgoingFollow(mixed $object, Actor $remoteTarget): ?Follow
    {
        if (is_array($object)) {
            $follower = $this->resolveLocalActorByUri($this->actorUri($object['actor'] ?? null));

            if ($follower !== null) {
                $follow = Follow::query()
                    ->where('follower_id', $follower->id)
                    ->where('following_id', $remoteTarget->id)
                    ->first();

                if ($follow !== null) {
                    return $follow;
                }
            }

            // Follow incorporato: object = URI del Group/Person remoto.
            $embeddedTargetUri = $this->normalizeUri($this->objectId($object['object'] ?? null) ?? '');

            if ($embeddedTargetUri !== '' && $embeddedTargetUri === $this->normalizeUri($remoteTarget->uri)) {
                if ($follower !== null) {
                    return Follow::query()
                        ->where('follower_id', $follower->id)
                        ->where('following_id', $remoteTarget->id)
                        ->first();
                }
            }
        }

        $objectId = $this->objectId($object);

        if (is_string($objectId) && $objectId !== '') {
            if (preg_match('#([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})\s*$#', $objectId, $matches) === 1) {
                $follow = Follow::query()
                    ->where('id', $matches[1])
                    ->where('following_id', $remoteTarget->id)
                    ->first();

                if ($follow !== null) {
                    return $follow;
                }
            }
        }

        // Ultima risorsa: un solo Follow pending locale verso questo remoto
        // (tipico dopo un Accept Lemmy con object mal formattato ma firmato
        // correttamente dal Group a cui ci si e' iscritti).
        $pending = Follow::query()
            ->where('following_id', $remoteTarget->id)
            ->where('status', Follow::STATUS_PENDING)
            ->whereIn('follower_id', function ($query) {
                $query->select('id')->from('actors')->where('is_local', true);
            })
            ->limit(2)
            ->get();

        return $pending->count() === 1 ? $pending->first() : null;
    }

    private function resolveLocalActorByUri(?string $uri): ?Actor
    {
        if ($uri === null || $uri === '') {
            return null;
        }

        $normalized = $this->normalizeUri($uri);

        return Actor::query()
            ->where('is_local', true)
            ->where(function ($query) use ($uri, $normalized) {
                $query->where('uri', $uri)->orWhere('uri', $normalized);
            })
            ->first();
    }

    /**
     * URI actor da stringa o oggetto incorporato `{ "id": "..." }`.
     */
    private function actorUri(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $this->normalizeUri($value);
        }

        if (is_array($value) && is_string($value['id'] ?? null) && $value['id'] !== '') {
            return $this->normalizeUri($value['id']);
        }

        return null;
    }

    /**
     * Normalizza percent-encoding nel path (`%40` → `@`) per confrontare URI
     * echoati da server remoti con quelli salvati localmente.
     */
    private function normalizeUri(string $uri): string
    {
        if ($uri === '') {
            return '';
        }

        $parts = parse_url($uri);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return rawurldecode($uri);
        }

        $path = isset($parts['path']) ? rawurldecode($parts['path']) : '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return $parts['scheme'].'://'.$parts['host'].$port.$path.$query.$fragment;
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

        if ($post === null) {
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
     * Condivisione classica (Person/bot che Annuncia un post) oppure
     * ritrasmissione FEP-1b12 (Group che Annuncia una Note/Page).
     * Se l'oggetto non e' ancora in cache, si recupera/incorpora come per
     * i Group: cosi' i boost di bot (es. tags.pub) compaiono nel feed di
     * chi li segue anche se il post originale non era conosciuto.
     *
     * @param  array<string, mixed>  $activity
     */
    private function handleAnnounce(array $activity, Actor $actor): string
    {
        [$targetUri, $embeddedNote] = $this->announceObject($activity['object'] ?? null);

        $post = $targetUri !== null ? $this->objects->resolvePost($targetUri) : null;

        // Post sconosciuto: fetch/upsert solo se chi Annuncia e' seguito in
        // locale (altrimenti non comparirebbe nel feed e occuperebbe solo spazio).
        if ($post === null) {
            if (! $this->hasLocalFollower($actor)) {
                return InboxItem::STATUS_IGNORED;
            }

            $post = $this->resolveAnnouncedRemotePost($targetUri, $embeddedNote);
        }

        if ($post === null) {
            return InboxItem::STATUS_IGNORED;
        }

        $post->loadMissing('actor');

        // Rilevanza: post di autore locale (boost del nostro contenuto) oppure
        // chi Annuncia e' seguito da almeno un Actor locale.
        if (! $post->actor->isLocal() && ! $this->hasLocalFollower($actor)) {
            return InboxItem::STATUS_IGNORED;
        }

        $occurredAt = null;

        if ($actor->isGroup()) {
            $occurredAt = $post->published_at;
        } elseif (! $post->actor->isLocal()
            && isset($activity['published'])
            && is_string($activity['published'])
        ) {
            $occurredAt = ActivityPubTimestamp::parse($activity['published']);
        }

        $this->announceManager->announce(
            $actor,
            $post,
            notify: $post->actor->isLocal(),
            occurredAt: $occurredAt,
        );

        return InboxItem::STATUS_PROCESSED;
    }

    /**
     * @return array{0: ?string, 1: ?array<string, mixed>}
     */
    private function announceObject(mixed $object): array
    {
        if (is_string($object) && $object !== '') {
            return [$object, null];
        }

        if (! is_array($object)) {
            return [null, null];
        }

        $postable = RemotePostObject::unwrap($object);

        if ($postable !== null) {
            $uri = is_string($postable['id'] ?? null) ? $postable['id'] : null;

            return [$uri, $postable];
        }

        return [$this->objectId($object), null];
    }

    /**
     * Risolve il post oggetto di un Announce non ancora in cache: Note/Page
     * incorporata oppure fetch HTTP dell'URI (Person e Group).
     *
     * @param  array<string, mixed>|null  $embeddedNote
     */
    private function resolveAnnouncedRemotePost(?string $targetUri, ?array $embeddedNote): ?Post
    {
        $note = $embeddedNote;

        if ($note === null && is_string($targetUri) && $targetUri !== '') {
            $note = $this->noteDocumentFetcher->fetch($targetUri);
        }

        if ($note === null || ! RemotePostObject::isPostable($note['type'] ?? null)) {
            return null;
        }

        $noteUri = is_string($note['id'] ?? null) ? $note['id'] : $targetUri;

        if (! is_string($noteUri) || $noteUri === '') {
            return null;
        }

        $existing = $this->objects->resolvePost($noteUri);

        if ($existing !== null) {
            return $existing;
        }

        // Solo post originali: le risposte annunciate senza padre in cache
        // non sono agganciabili in modo utile.
        if (($note['inReplyTo'] ?? null) !== null) {
            return null;
        }

        $authorUri = RemotePostObject::primaryAuthorUri($note['attributedTo'] ?? null);

        if ($authorUri === null) {
            return null;
        }

        // Come l'outbox Group: l'autore del post boostato e' spesso sconosciuto
        // (tags.pub Annuncia solo l'URI). ObjectResolver non basta.
        $author = $this->objects->resolveActor($authorUri)
            ?? $this->remoteActorResolver->resolveByUri($authorUri);

        if ($author === null) {
            return null;
        }

        $visibility = $this->noteUpserter->visibilityFromAudience($note);

        if (! in_array($visibility, [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_UNLISTED], true)) {
            return null;
        }

        $body = RemotePostObject::body($note);
        $publishedAt = ActivityPubTimestamp::parse(
            isset($note['published']) && is_string($note['published']) ? $note['published'] : null,
        );

        return $this->noteUpserter->upsertPost($note, $noteUri, $author, $body, $publishedAt, notifyMentions: false);
    }

    private function hasLocalFollower(Actor $actor): bool
    {
        return DB::table('follows')
            ->where('following_id', $actor->id)
            ->where('status', Follow::STATUS_ACCEPTED)
            ->whereIn('follower_id', function ($query) {
                $query->select('id')->from('actors')->where('is_local', true);
            })
            ->exists();
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
            $this->commentSoftDeleter->delete($target);
        }

        return InboxItem::STATUS_PROCESSED;
    }

    /**
     * Un "Update" trasporta rappresentazioni diverse a seconda del tipo del
     * suo "object": una Note (post o commento gia' noto, o nuovo se mai
     * ricevuto) oppure un intero documento Person/Group, usato dagli altri
     * server per notificare un cambio al profilo di un proprio utente (nome,
     * biografia, avatar, copertina, account protetto). In quest'ultimo caso
     * il documento viene applicato direttamente alla cache locale
     * dell'Actor, senza bisogno di rifare la richiesta HTTP che
     * {@see RemoteActorResolver} farebbe comunque al prossimo utilizzo (ma
     * solo dopo la scadenza della cache).
     *
     * @param  array<string, mixed>  $activity
     */
    private function handleUpdate(array $activity, Actor $signer): string
    {
        $object = $activity['object'] ?? null;
        $type = is_array($object) ? ($object['type'] ?? null) : null;

        if (is_array($object) && RemotePostObject::isPostable($type)) {
            return $this->handleCreateOrUpdate($activity, $signer);
        }

        return match ($type) {
            'Person', 'Group', 'Service', 'Application', 'Organization' => $this->handleUpdateActor($object, $signer),
            default => InboxItem::STATUS_IGNORED,
        };
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function handleUpdateActor(array $document, Actor $signer): string
    {
        $documentId = is_string($document['id'] ?? null) ? $document['id'] : null;

        if ($documentId === null || $documentId !== $signer->uri) {
            // Un Actor puo' aggiornare solo il proprio documento: l'id
            // dichiarato deve coincidere con chi ha firmato la richiesta,
            // gia' verificata a livello di trasporto.
            return InboxItem::STATUS_IGNORED;
        }

        $updated = $this->remoteActorResolver->applyRemoteDocument($document, $signer->uri);

        return $updated !== null ? InboxItem::STATUS_PROCESSED : InboxItem::STATUS_IGNORED;
    }

    /**
     * Gestisce Create/Update di oggetti postabili (Note, Page, Article,
     * Video, Image). Le risposte (inReplyTo) restano solo Note. Se "object"
     * e' solo un id remoto, viene recuperato via fetch firmato.
     *
     * @param  array<string, mixed>  $activity
     */
    private function handleCreateOrUpdate(array $activity, Actor $actor): string
    {
        $note = $this->resolveCreateObject($activity['object'] ?? null);

        if ($note === null || ! RemotePostObject::isPostable($note['type'] ?? null)) {
            return InboxItem::STATUS_IGNORED;
        }

        $note = $this->mergeActivityAudience($activity, $note);
        $note = $this->ensureNoteContent($activity, $note);

        $noteUri = $note['id'] ?? null;

        if (! is_string($noteUri) || $noteUri === '') {
            return InboxItem::STATUS_IGNORED;
        }

        // PeerTube e altri mettono Person+Group in attributedTo: il firmatario
        // deve essere uno degli autori dichiarati, non necessariamente l'unico.
        if (! RemotePostObject::authorMatches($note['attributedTo'] ?? null, $actor->uri)) {
            return InboxItem::STATUS_IGNORED;
        }

        $authorUri = RemotePostObject::primaryAuthorUri($note['attributedTo'] ?? null, $actor->uri) ?? $actor->uri;
        $author = $authorUri === $actor->uri
            ? $actor
            : ($this->objects->resolveActor($authorUri) ?? $actor);

        $inReplyTo = is_string($note['inReplyTo'] ?? null) ? $note['inReplyTo'] : null;
        $parentPost = null;
        $parentComment = null;

        if ($inReplyTo !== null) {
            // I commenti federati sono Note; Article/Video/Page con inReplyTo
            // non li trattiamo come thread di risposta.
            if (! RemotePostObject::hasType($note['type'] ?? null, 'Note')) {
                return InboxItem::STATUS_IGNORED;
            }

            $parentComment = $this->objects->resolveComment($inReplyTo);
            $parentPost = $parentComment !== null ? null : $this->objects->resolvePost($inReplyTo);

            if ($parentComment === null && $parentPost === null) {
                return InboxItem::STATUS_IGNORED;
            }
        }

        if (! $this->isRelevant($author, $note, $parentPost, $parentComment)) {
            return InboxItem::STATUS_IGNORED;
        }

        $body = RemotePostObject::body($note);

        if ($this->noteUpserter->visibilityFromAudience($note) === Post::VISIBILITY_DIRECT) {
            $body = $this->sanitizeDirectMessageBody($note, $body);
        }

        $publishedAt = ActivityPubTimestamp::parse(
            isset($note['published']) && is_string($note['published']) ? $note['published'] : null,
        );

        $isDirectMessageReply = $this->isDirectMessageReply($note, $parentPost, $parentComment);
        $isReply = ($parentPost !== null || $parentComment !== null) && ! $isDirectMessageReply;

        return DB::transaction(function () use ($isReply, $isDirectMessageReply, $note, $noteUri, $author, $body, $publishedAt, $parentPost, $parentComment) {
            if ($isReply) {
                $comment = $this->noteUpserter->upsertComment($note, $noteUri, $author, $body, $parentPost, $parentComment);

                return $comment !== null ? InboxItem::STATUS_PROCESSED : InboxItem::STATUS_IGNORED;
            }

            $this->noteUpserter->upsertPost(
                $note,
                $noteUri,
                $author,
                $body,
                $publishedAt,
                directMessageThreadParent: $isDirectMessageReply ? $parentPost : null,
            );

            return InboxItem::STATUS_PROCESSED;
        });
    }

    /**
     * Risposte a un messaggio privato (typical Mastodon DM thread con
     * {@code inReplyTo}) restano messaggi nella conversazione, non commenti.
     *
     * @param  array<string, mixed>  $note
     */
    private function isDirectMessageReply(array $note, ?Post $parentPost, ?Comment $parentComment): bool
    {
        if ($parentPost === null || $parentComment !== null) {
            return false;
        }

        if ($parentPost->visibility === Post::VISIBILITY_DIRECT) {
            return true;
        }

        return $this->noteUpserter->visibilityFromAudience($note) === Post::VISIBILITY_DIRECT;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveCreateObject(mixed $object): ?array
    {
        if (is_string($object) && $object !== '') {
            return $this->noteDocumentFetcher->fetch($object);
        }

        if (! is_array($object)) {
            return null;
        }

        if (RemotePostObject::isPostable($object['type'] ?? null)) {
            return $object;
        }

        $unwrapped = RemotePostObject::unwrap($object);

        if ($unwrapped !== null) {
            return $unwrapped;
        }

        if (is_string($object['id'] ?? null) && $object['id'] !== '') {
            return $this->noteDocumentFetcher->fetch($object['id']);
        }

        return null;
    }

    /**
     * Mastodon e altri server spesso inviano il Create con to/cc sull'attivita'
     * e una Note incorporata senza audience completa.
     *
     * @param  array<string, mixed>  $activity
     * @param  array<string, mixed>  $note
     * @return array<string, mixed>
     */
    private function mergeActivityAudience(array $activity, array $note): array
    {
        foreach (['to', 'cc'] as $field) {
            $noteValue = $note[$field] ?? null;
            $activityValue = $activity[$field] ?? null;

            if ($this->audienceFieldIsEmpty($noteValue) && ! $this->audienceFieldIsEmpty($activityValue)) {
                $note[$field] = $activityValue;
            }
        }

        return $note;
    }

    private function audienceFieldIsEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return $value === '';
        }

        return is_array($value) && $value === [];
    }

    /**
     * Recupera il testo mancante quando l'oggetto e' uno stub (typical DM
     * Mastodon). Per i direct message il fetch va firmato come destinatario
     * locale, altrimenti molti server non restituiscono {@code content}.
     *
     * @param  array<string, mixed>  $activity
     * @param  array<string, mixed>  $note
     * @return array<string, mixed>
     */
    private function ensureNoteContent(array $activity, array $note): array
    {
        if (RemotePostObject::hasRawContent($note)) {
            return $note;
        }

        $noteUri = $note['id'] ?? null;

        if (! is_string($noteUri) || $noteUri === '') {
            return $note;
        }

        $localRecipient = $this->resolveLocalRecipientFromAudience($note);
        $fetched = $this->noteDocumentFetcher->fetch($noteUri, $localRecipient);

        if ($fetched === null || ! RemotePostObject::hasRawContent($fetched)) {
            return $note;
        }

        return $this->mergeActivityAudience($activity, $fetched);
    }

    /**
     * @param  array<string, mixed>  $note
     */
    private function resolveLocalRecipientFromAudience(array $note): ?Actor
    {
        foreach (['to', 'cc'] as $field) {
            $value = $note[$field] ?? null;

            if (is_string($value) && $value !== '') {
                $actor = $this->localPersonActorForUri($value);

                if ($actor !== null) {
                    return $actor;
                }

                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            foreach ($value as $item) {
                if (! is_string($item) || $item === '') {
                    continue;
                }

                $actor = $this->localPersonActorForUri($item);

                if ($actor !== null) {
                    return $actor;
                }
            }
        }

        return null;
    }

    private function localPersonActorForUri(string $uri): ?Actor
    {
        if (! $this->isLocalActorUri($uri)) {
            return null;
        }

        $actor = Actor::query()->where('uri', $uri)->first();

        if ($actor === null || ! $actor->isLocal() || ! $actor->isPerson() || ! $actor->isActive()) {
            return null;
        }

        return $actor;
    }

    /**
     * @param  array<string, mixed>  $note
     */
    private function sanitizeDirectMessageBody(array $note, string $body): string
    {
        if (RemotePostObject::hasRawContent($note)) {
            return $body;
        }

        $trimmed = trim($body);

        if ($trimmed === '') {
            return $body;
        }

        $noteUri = is_string($note['id'] ?? null) ? trim($note['id']) : null;

        if ($noteUri !== null && $trimmed === $noteUri) {
            return '';
        }

        return $body;
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

        return $this->mentionsLocalActor($note) || $this->addressesLocalActor($note);
    }

    /**
     * @param  array<string, mixed>  $note
     */
    private function addressesLocalActor(array $note): bool
    {
        foreach (['to', 'cc'] as $field) {
            $value = $note[$field] ?? null;

            if (is_string($value) && $value !== '' && $this->isLocalActorUri($value)) {
                return true;
            }

            if (! is_array($value)) {
                continue;
            }

            foreach ($value as $item) {
                if (is_string($item) && $item !== '' && $this->isLocalActorUri($item)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isLocalActorUri(string $uri): bool
    {
        if (in_array($uri, [
            NoteSerializer::PUBLIC_STREAM,
            'as:Public',
            'Public',
        ], true)) {
            return false;
        }

        if (str_ends_with($uri, '/followers')) {
            return false;
        }

        return Actor::query()->where('uri', $uri)->where('is_local', true)->exists();
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
