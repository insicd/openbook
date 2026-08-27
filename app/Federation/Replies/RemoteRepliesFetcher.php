<?php

namespace App\Federation\Replies;

use App\Domain\Comments\Comment;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Federation\Actors\RemoteActorResolver;
use App\Federation\Fetch\FederationFetchSigner;
use App\Federation\Inbox\RemoteNoteUpserter;
use App\Federation\Inbox\RemotePostObject;
use App\Federation\Posts\RemoteReactionCountSync;
use App\Federation\Resolution\ObjectResolver;
use App\Infrastructure\Security\Http\SafeHttpClient;
use App\Infrastructure\Security\Http\SsrfViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Recupera le risposte pubbliche dalla collection "replies" di una Note
 * remota gia' in cache locale. Necessario perche' i commenti di terzi su
 * un post seguito arrivano in inbox solo se indirizzati a questa istanza.
 *
 * Dalla stessa Note (stesso GET, stesso TTL) aggiorna anche i contatori
 * likes/announces con {@code totalItems} di {@code likes} e {@code shares}:
 * se il totale non e' inline si dereferenzia al massimo una volta ciascuna
 * collection, senza paginare gli attori.
 *
 * Su Mastodon la prima pagina di "replies" contiene solo le auto-risposte
 * dell'autore (spesso vuota): le risposte di terzi stanno nelle pagine
 * successive (`next`), che vanno quindi seguite.
 */
final class RemoteRepliesFetcher
{
    private const MAX_ITEMS = 40;

    private const MAX_PAGES = 5;

    private const ACCEPT = 'application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"';

    public function __construct(
        private readonly SafeHttpClient $httpClient,
        private readonly RemoteNoteUpserter $noteUpserter,
        private readonly RemoteActorResolver $remoteActorResolver,
        private readonly ObjectResolver $objects,
        private readonly FederationFetchSigner $fetchSigner,
    ) {}

    public function fetchReplies(Post $post, bool $force = false): void
    {
        if (! $post->isRemote() || blank($post->uri) || ! $post->isPublished()) {
            return;
        }

        $ttlHours = (int) config('openbook.federation.replies_cache_ttl_hours', 6);

        if (
            ! $force
            && $post->replies_fetched_at !== null
            && $post->replies_fetched_at->gt(Carbon::now()->subHours($ttlHours))
        ) {
            return;
        }

        // Aggiornato subito, prima della richiesta: un server remoto
        // irraggiungibile non deve rallentare ogni successivo caricamento.
        $post->forceFill(['replies_fetched_at' => now()])->saveQuietly();

        $signingActor = $this->fetchSigner->resolve();
        $note = $this->fetchDocument($post->uri, $signingActor);

        if ($note === null) {
            Log::channel('single')->info('federation.replies.note_unavailable', [
                'post_uri' => $post->uri,
            ]);

            return;
        }

        $this->syncReactionTotals($post, $note, $signingActor);

        if (! $this->isType($note['type'] ?? null, 'Note')) {
            Log::channel('single')->info('federation.replies.note_unavailable', [
                'post_uri' => $post->uri,
            ]);

            return;
        }

        $items = $this->resolveRepliesItems($note['replies'] ?? null, $signingActor);

        Log::channel('single')->info('federation.replies.fetched', [
            'post_uri' => $post->uri,
            'items' => count($items),
        ]);

        foreach ($items as $item) {
            $this->ingestItem($item, $post, $signingActor);
        }
    }

    /**
     * @param  array<string, mixed>  $note
     */
    private function syncReactionTotals(Post $post, array $note, ?Actor $signingActor): void
    {
        $likesTotal = RemotePostObject::collectionTotalItems($note['likes'] ?? null)
            ?? $this->fetchCollectionTotal($note['likes'] ?? null, $signingActor);
        $sharesTotal = RemotePostObject::collectionTotalItems($note['shares'] ?? null)
            ?? $this->fetchCollectionTotal($note['shares'] ?? null, $signingActor);

        RemoteReactionCountSync::apply($post, $likesTotal, $sharesTotal);

        if ($likesTotal !== null || $sharesTotal !== null) {
            Log::channel('single')->info('federation.reactions.synced', [
                'post_uri' => $post->uri,
                'likes' => $likesTotal,
                'shares' => $sharesTotal,
            ]);
        }
    }

    private function fetchCollectionTotal(mixed $collection, ?Actor $signingActor): ?int
    {
        $url = RemotePostObject::collectionUrl($collection);

        if ($url === null) {
            return null;
        }

        $document = $this->fetchDocument($url, $signingActor);

        return $document === null ? null : RemotePostObject::collectionTotalItems($document);
    }

    /**
     * @return list<array<string, mixed>|string>
     */
    private function resolveRepliesItems(mixed $replies, ?Actor $signingActor): array
    {
        if ($replies === null) {
            return [];
        }

        $collected = [];
        $visited = [];
        $page = null;

        if (is_string($replies) && $replies !== '') {
            $page = $this->fetchDocument($replies, $signingActor);
        } elseif (is_array($replies)) {
            $direct = $this->pageItems($replies);
            $this->appendItems($collected, $direct);

            $first = $replies['first'] ?? null;

            if (is_array($first)) {
                $page = $this->materializePage($first, $signingActor, $visited);
            } elseif (is_string($first) && $first !== '') {
                $page = $this->fetchDocument($first, $signingActor);
            } elseif ($direct === [] && $this->isCollectionType($replies['type'] ?? null)) {
                // Collection senza "first": prova a dereferenziare l'id
                // della collection stessa (tipico di alcuni server GtS).
                $collectionId = $replies['id'] ?? null;
                $page = (is_string($collectionId) && $collectionId !== '')
                    ? $this->fetchDocument($collectionId, $signingActor)
                    : null;
            }
        }

        $pages = 0;

        while ($page !== null && $pages < self::MAX_PAGES && count($collected) < self::MAX_ITEMS) {
            $pages++;
            $this->appendItems($collected, $this->pageItems($page));

            $next = $page['next'] ?? null;

            if (is_array($next)) {
                $page = $this->materializePage($next, $signingActor, $visited);

                continue;
            }

            if (! is_string($next) || $next === '' || isset($visited[$next])) {
                break;
            }

            $visited[$next] = true;
            $page = $this->fetchDocument($next, $signingActor);
        }

        return array_slice($collected, 0, self::MAX_ITEMS);
    }

    /**
     * Una CollectionPage puo' arrivare inline senza items (solo id): in quel
     * caso va scaricata dall'id prima di leggerla.
     *
     * @param  array<string, mixed>  $page
     * @param  array<string, true>  $visited
     * @return array<string, mixed>|null
     */
    private function materializePage(array $page, ?Actor $signingActor, array &$visited): ?array
    {
        if ($this->pageItems($page) !== []) {
            return $page;
        }

        $id = $page['id'] ?? null;

        if (! is_string($id) || $id === '' || isset($visited[$id])) {
            return $page;
        }

        $visited[$id] = true;
        $fetched = $this->fetchDocument($id, $signingActor);

        return $fetched ?? $page;
    }

    /**
     * @param  list<array<string, mixed>|string>  $collected
     * @param  list<array<string, mixed>|string>  $items
     */
    private function appendItems(array &$collected, array $items): void
    {
        foreach ($items as $item) {
            $collected[] = $item;
        }
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<array<string, mixed>|string>
     */
    private function pageItems(array $document): array
    {
        $items = $document['orderedItems'] ?? $document['items'] ?? null;

        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter(
            $items,
            fn ($item) => is_array($item) || (is_string($item) && $item !== ''),
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchDocument(string $url, ?Actor $signingActor): ?array
    {
        try {
            $response = $this->httpClient->get($url, ['Accept' => self::ACCEPT], $signingActor);
        } catch (SsrfViolationException $exception) {
            Log::channel('single')->info('federation.replies.fetch_blocked', [
                'url' => $url,
                'reason' => $exception->getMessage(),
            ]);

            return null;
        } catch (\Throwable $exception) {
            Log::channel('single')->info('federation.replies.fetch_error', [
                'url' => $url,
                'reason' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::channel('single')->info('federation.replies.fetch_failed', [
                'url' => $url,
                'status' => $response->status,
            ]);

            return null;
        }

        return $response->json();
    }

    /**
     * @param  array<string, mixed>|string  $item
     */
    private function ingestItem(array|string $item, Post $post, ?Actor $signingActor): void
    {
        $note = is_string($item) ? $this->fetchDocument($item, $signingActor) : $item;

        if ($note === null) {
            Log::channel('single')->info('federation.replies.skip', [
                'post_uri' => $post->uri,
                'reason' => 'item_unavailable',
                'item' => is_string($item) ? $item : ($item['id'] ?? null),
            ]);

            return;
        }

        if ($this->isType($note['type'] ?? null, 'Create') && is_array($note['object'] ?? null)) {
            $note = $note['object'];
        }

        if (! $this->isType($note['type'] ?? null, 'Note')) {
            Log::channel('single')->info('federation.replies.skip', [
                'post_uri' => $post->uri,
                'reason' => 'not_a_note',
                'type' => $note['type'] ?? null,
                'item' => $note['id'] ?? null,
            ]);

            return;
        }

        $noteUri = is_string($note['id'] ?? null) ? $note['id'] : null;
        $inReplyTo = $this->objectUri($note['inReplyTo'] ?? null);
        $attributedTo = $this->actorUri($note['attributedTo'] ?? null);

        if ($noteUri === null || $noteUri === '' || $inReplyTo === null || $inReplyTo === '' || $attributedTo === null) {
            Log::channel('single')->info('federation.replies.skip', [
                'post_uri' => $post->uri,
                'reason' => 'missing_fields',
                'item' => $noteUri,
                'in_reply_to' => $inReplyTo,
                'attributed_to' => $attributedTo,
            ]);

            return;
        }

        // Commento locale gia' presente (/comments/{uuid} senza colonna uri):
        // non re-ingerire (evita UniqueConstraint creando un Actor "remoto"
        // omomorfo sul dominio locale).
        $existingComment = $this->objects->resolveComment($noteUri);

        if ($existingComment !== null) {
            $existingComment->loadMissing('actor');

            if ($existingComment->actor?->isLocal()) {
                Log::channel('single')->info('federation.replies.skip', [
                    'post_uri' => $post->uri,
                    'reason' => 'local_comment',
                    'item' => $noteUri,
                ]);

                return;
            }
        }

        [$parentPost, $parentComment] = $this->resolveParents($inReplyTo, $post);

        if ($parentPost === null && $parentComment === null) {
            Log::channel('single')->info('federation.replies.skip', [
                'post_uri' => $post->uri,
                'reason' => 'unrelated_in_reply_to',
                'item' => $noteUri,
                'in_reply_to' => $inReplyTo,
            ]);

            return;
        }

        $visibility = $this->noteUpserter->visibilityFromAudience($note);

        if (! in_array($visibility, [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_UNLISTED], true)) {
            Log::channel('single')->info('federation.replies.skip', [
                'post_uri' => $post->uri,
                'reason' => 'not_public',
                'item' => $noteUri,
                'visibility' => $visibility,
            ]);

            return;
        }

        // ObjectResolver riconosce anche alias locali (/@user, /users/user).
        $actor = $this->objects->resolveActor($attributedTo);

        if ($actor === null) {
            $actor = $this->remoteActorResolver->resolveByUri($attributedTo);
        }

        if ($actor === null || $actor->isLocal()) {
            Log::channel('single')->info('federation.replies.skip', [
                'post_uri' => $post->uri,
                'reason' => $actor?->isLocal() ? 'local_actor' : 'actor_unresolved',
                'item' => $noteUri,
                'attributed_to' => $attributedTo,
            ]);

            return;
        }

        $body = RemotePostObject::body($note);

        $this->noteUpserter->upsertComment(
            $note,
            $noteUri,
            $actor,
            $body,
            $parentPost,
            $parentComment,
            notifyMentions: false,
        );
    }

    /**
     * @return array{0: ?Post, 1: ?Comment}
     */
    private function resolveParents(string $inReplyTo, Post $post): array
    {
        if ($this->sameObjectUri($inReplyTo, (string) $post->uri)) {
            return [$post, null];
        }

        $parentComment = Comment::query()
            ->where('post_id', $post->id)
            ->where(function ($query) use ($inReplyTo) {
                $query->where('uri', $inReplyTo)
                    ->orWhere('uri', rtrim($inReplyTo, '/'))
                    ->orWhere('uri', $inReplyTo.'/');
            })
            ->first();

        if ($parentComment !== null) {
            return [null, $parentComment];
        }

        return [null, null];
    }

    private function objectUri(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_array($value) && is_string($value['id'] ?? null) && $value['id'] !== '') {
            return $value['id'];
        }

        return null;
    }

    private function actorUri(mixed $attributedTo): ?string
    {
        if (is_string($attributedTo) && $attributedTo !== '') {
            return $attributedTo;
        }

        if (! is_array($attributedTo)) {
            return null;
        }

        if (is_string($attributedTo['id'] ?? null) && $attributedTo['id'] !== '') {
            return $attributedTo['id'];
        }

        $first = $attributedTo[0] ?? null;

        if (is_string($first) && $first !== '') {
            return $first;
        }

        if (is_array($first) && is_string($first['id'] ?? null) && $first['id'] !== '') {
            return $first['id'];
        }

        return null;
    }

    private function sameObjectUri(string $a, string $b): bool
    {
        return rtrim($a, '/') === rtrim($b, '/');
    }

    private function isType(mixed $type, string $expected): bool
    {
        if ($type === $expected) {
            return true;
        }

        return is_array($type) && in_array($expected, $type, true);
    }

    private function isCollectionType(mixed $type): bool
    {
        return $this->isType($type, 'Collection')
            || $this->isType($type, 'OrderedCollection')
            || $this->isType($type, 'CollectionPage')
            || $this->isType($type, 'OrderedCollectionPage');
    }
}
