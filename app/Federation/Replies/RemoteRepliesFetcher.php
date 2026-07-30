<?php

namespace App\Federation\Replies;

use App\Domain\Comments\Comment;
use App\Domain\Posts\Post;
use App\Federation\Actors\RemoteActorResolver;
use App\Federation\Inbox\RemoteContentSanitizer;
use App\Federation\Inbox\RemoteNoteUpserter;
use App\Infrastructure\Security\Http\SafeHttpClient;
use App\Infrastructure\Security\Http\SsrfViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Recupera le risposte pubbliche dalla collection "replies" di una Note
 * remota gia' in cache locale. Necessario perche' i commenti di terzi su
 * un post seguito arrivano in inbox solo se indirizzati a questa istanza.
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
    ) {}

    public function fetchReplies(Post $post): void
    {
        if (! $post->isRemote() || blank($post->uri) || ! $post->isPublished()) {
            return;
        }

        $ttlHours = (int) config('openbook.federation.replies_cache_ttl_hours', 6);

        if ($post->replies_fetched_at !== null && $post->replies_fetched_at->gt(Carbon::now()->subHours($ttlHours))) {
            return;
        }

        // Aggiornato subito, prima della richiesta: un server remoto
        // irraggiungibile non deve rallentare ogni successivo caricamento.
        $post->forceFill(['replies_fetched_at' => now()])->saveQuietly();

        $note = $this->fetchDocument($post->uri);

        if ($note === null || ! $this->isType($note['type'] ?? null, 'Note')) {
            Log::channel('single')->info('federation.replies.note_unavailable', [
                'post_uri' => $post->uri,
            ]);

            return;
        }

        $items = $this->resolveRepliesItems($note['replies'] ?? null);

        foreach ($items as $item) {
            $this->ingestItem($item, $post);
        }
    }

    /**
     * @return list<array<string, mixed>|string>
     */
    private function resolveRepliesItems(mixed $replies): array
    {
        if ($replies === null) {
            return [];
        }

        $collected = [];
        $visited = [];
        $page = null;

        if (is_string($replies) && $replies !== '') {
            $page = $this->fetchDocument($replies);
        } elseif (is_array($replies)) {
            $direct = $this->pageItems($replies);
            $this->appendItems($collected, $direct);

            $first = $replies['first'] ?? null;

            if (is_array($first)) {
                $page = $first;
            } elseif (is_string($first) && $first !== '') {
                $page = $this->fetchDocument($first);
            } elseif ($direct === [] && $this->isCollectionType($replies['type'] ?? null)) {
                // Collection senza "first" ma con items/orderedItems gia' letti sopra.
                $page = null;
            }
        }

        $pages = 0;

        while ($page !== null && $pages < self::MAX_PAGES && count($collected) < self::MAX_ITEMS) {
            $pages++;
            $this->appendItems($collected, $this->pageItems($page));

            $next = $page['next'] ?? null;

            if (! is_string($next) || $next === '' || isset($visited[$next])) {
                break;
            }

            $visited[$next] = true;
            $page = $this->fetchDocument($next);
        }

        return array_slice($collected, 0, self::MAX_ITEMS);
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
    private function fetchDocument(string $url): ?array
    {
        try {
            $response = $this->httpClient->get($url, ['Accept' => self::ACCEPT]);
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
    private function ingestItem(array|string $item, Post $post): void
    {
        $note = is_string($item) ? $this->fetchDocument($item) : $item;

        if ($note === null) {
            return;
        }

        if ($this->isType($note['type'] ?? null, 'Create') && is_array($note['object'] ?? null)) {
            $note = $note['object'];
        }

        if (! $this->isType($note['type'] ?? null, 'Note')) {
            return;
        }

        $noteUri = is_string($note['id'] ?? null) ? $note['id'] : null;
        $inReplyTo = is_string($note['inReplyTo'] ?? null) ? $note['inReplyTo'] : null;
        $attributedTo = $this->actorUri($note['attributedTo'] ?? null);

        if ($noteUri === null || $noteUri === '' || $inReplyTo === null || $inReplyTo === '' || $attributedTo === null) {
            return;
        }

        [$parentPost, $parentComment] = $this->resolveParents($inReplyTo, $post);

        if ($parentPost === null && $parentComment === null) {
            return;
        }

        $visibility = $this->noteUpserter->visibilityFromAudience($note);

        if (! in_array($visibility, [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_UNLISTED], true)) {
            return;
        }

        $actor = $this->remoteActorResolver->resolveByUri($attributedTo);

        if ($actor === null || $actor->isLocal()) {
            return;
        }

        $body = RemoteContentSanitizer::toPlainText((string) ($note['content'] ?? ''));

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
