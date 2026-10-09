<?php

namespace App\Federation\Outbox;

use App\Application\Services\DomainBlockManager;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Federation\Fetch\FederationFetchSigner;
use App\Federation\Inbox\RemoteNoteDocumentFetcher;
use App\Federation\Inbox\RemotePostObject;
use App\Federation\Support\ActivityPubUri;
use App\Infrastructure\Security\Http\SafeHttpClient;

/** A small ordered snapshot, independent of the ordinary profile timeline. */
final class RemoteFeaturedPostsFetcher
{
    private const MAX_ITEMS = 20;

    private const MAX_PAGES = 3;

    public function __construct(
        private readonly SafeHttpClient $http,
        private readonly FederationFetchSigner $signer,
        private readonly RemoteNoteDocumentFetcher $notes,
        private readonly RemoteOutboxFetcher $outbox,
        private readonly DomainBlockManager $domainBlocks,
    ) {}

    public function refreshIfStale(Actor $actor): void
    {
        if ($actor->isLocal() || $actor->isFeed() || ! $actor->isActive()) {
            return;
        }

        $actor->loadMissing('endpoints');
        $url = $actor->endpoints?->featured;
        if (blank($url)) {
            return;
        }

        $ttl = max(1, (int) config('openbook.federation.posts_cache_ttl_hours', 6));
        if ($actor->featured_fetched_at?->gt(now()->subHours($ttl))) {
            return;
        }

        // Record failures too: an unavailable origin must not delay every visit.
        $actor->forceFill(['featured_fetched_at' => now()])->saveQuietly();
        $items = $this->collectionItems($url);
        if ($items === null) {
            return;
        }

        $ids = [];
        foreach ($items as $item) {
            $post = $this->importItem($item, $actor);
            if ($post !== null) {
                $ids[] = $post->id;
            }
        }

        // A concurrent Actor Update may have replaced or removed the endpoint.
        if ($actor->endpoints()->value('featured') === $url) {
            $actor->forceFill(['featured_post_ids' => array_values(array_unique($ids))])->saveQuietly();
        }
    }

    /** @return list<mixed>|null */
    private function collectionItems(string $url): ?array
    {
        $page = $this->fetch($url);
        $items = [];
        $visited = [$url];
        for ($count = 0; $count < self::MAX_PAGES; $count++) {
            if ($page === null || ! in_array($page['type'] ?? null, ['Collection', 'OrderedCollection', 'CollectionPage', 'OrderedCollectionPage'], true)) {
                return null;
            }

            $entries = $page['orderedItems'] ?? $page['items'] ?? null;
            if (is_array($entries)) {
                $items = array_merge($items, array_values($entries));
                if (count($items) >= self::MAX_ITEMS) {
                    return array_slice($items, 0, self::MAX_ITEMS);
                }
                $next = $page['next'] ?? null;
            } else {
                $next = $page['first'] ?? null;
                if ($next === null) {
                    return ($page['totalItems'] ?? null) === 0 ? $items : null;
                }
            }

            if ($next === null) {
                return $items;
            }
            if ($count + 1 >= self::MAX_PAGES) {
                return null;
            }
            if (is_array($next) && isset($next['type'])) {
                $page = $next;

                continue;
            }
            $next = is_array($next) ? ($next['id'] ?? null) : $next;
            if (! is_string($next) || in_array($next, $visited, true)) {
                return null;
            }
            $visited[] = $next;
            $page = $this->fetch($next);
        }

        // Incomplete pagination cannot safely remove previously known pins.
        return null;
    }

    /** @return array<string, mixed>|null */
    private function fetch(string $url): ?array
    {
        if ($this->domainBlocks->isBlockedUrl($url)) {
            return null;
        }
        try {
            $response = $this->http->get($url, ['Accept' => 'application/activity+json'], $this->signer->resolve());

            return $response->successful() ? $response->json() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function importItem(mixed $item, Actor $actor): ?Post
    {
        $note = is_array($item) ? RemotePostObject::unwrap($item) : null;
        $uri = $note['id'] ?? (is_array($item) ? ($item['id'] ?? null) : $item);
        if (! is_string($uri) || ! in_array(parse_url($uri, PHP_URL_SCHEME), ['http', 'https'], true)
            || $this->domainBlocks->isBlockedUrl($uri)) {
            return null;
        }

        $existing = Post::query()->where('uri', $uri)->first();
        if ($existing !== null && ($existing->actor_id !== $actor->id || $existing->status !== Post::STATUS_PUBLISHED)) {
            return null;
        }

        if ($note === null && $existing !== null) {
            return $existing;
        }

        // Inline content can only assert objects belonging to the origin itself.
        // For cross-host references, verify the object at its canonical endpoint.
        if ($note === null || strtolower((string) parse_url($uri, PHP_URL_HOST)) !== strtolower((string) parse_url($actor->uri, PHP_URL_HOST))) {
            $note = $this->notes->fetch($uri);
        }
        if ($note === null || ! is_string($note['id'] ?? null) || ! ActivityPubUri::same($note['id'], $uri)
            || ! RemotePostObject::authorMatches($note['attributedTo'] ?? null, $actor->uri)
            || ($note['inReplyTo'] ?? null) !== null || RemotePostObject::isExplicitDirectMessage($note)) {
            return null;
        }

        return $this->outbox->upsertPublicPost($note, $actor);
    }
}
