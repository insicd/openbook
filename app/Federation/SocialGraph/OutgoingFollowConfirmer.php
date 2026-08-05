<?php

namespace App\Federation\SocialGraph;

use App\Application\Services\FollowManager;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\RemoteActorResolver;
use App\Federation\Fetch\FederationFetchSigner;
use App\Infrastructure\Security\Http\SafeHttpClient;
use App\Infrastructure\Security\Http\SsrfViolationException;
use Illuminate\Support\Facades\Log;

/**
 * Conferma un Follow in uscita ancora "pending" quando il server remoto ha
 * gia' aggiunto il follower alla propria collection followers ma non ha
 * (ancora) consegnato l'Accept — tipico di tags.pub / activitypub-bot.
 */
final class OutgoingFollowConfirmer
{
    private const MAX_PAGES = 3;

    public function __construct(
        private readonly SafeHttpClient $httpClient,
        private readonly FederationFetchSigner $fetchSigner,
        private readonly RemoteActorResolver $remoteActorResolver,
        private readonly FollowManager $followManager,
    ) {}

    public function confirm(Follow $follow): bool
    {
        $follow->loadMissing(['follower', 'following.endpoints']);

        $follower = $follow->follower;
        $target = $follow->following;

        if ($follower === null || $target === null
            || ! $follower->isLocal()
            || $target->isLocal()
            || $follow->status !== Follow::STATUS_PENDING
        ) {
            return false;
        }

        $followersUrl = $target->endpoints?->followers;

        if (blank($followersUrl)) {
            $refreshed = $this->remoteActorResolver->refresh($target);

            if ($refreshed !== null) {
                $target = $refreshed;
                $followersUrl = $target->endpoints?->followers;
            }
        }

        if (blank($followersUrl)) {
            Log::channel('single')->info('federation.follow_confirm_skipped', [
                'reason' => 'missing_followers_collection',
                'follow_id' => $follow->id,
                'target_uri' => $target->uri,
            ]);

            return false;
        }

        $localUri = $follower->activityPubId();

        if (! $this->followersCollectionContains($followersUrl, $localUri)) {
            return false;
        }

        $this->followManager->markOutgoingAccepted($follow);

        Log::channel('single')->info('federation.follow_confirmed_via_collection', [
            'follow_id' => $follow->id,
            'follower_uri' => $localUri,
            'target_uri' => $target->uri,
            'followers' => $followersUrl,
        ]);

        return true;
    }

    private function followersCollectionContains(string $collectionUrl, string $actorUri): bool
    {
        $document = $this->fetchJson($collectionUrl);

        if ($document === null) {
            return false;
        }

        if ($this->itemsContainActor($document, $actorUri)) {
            return true;
        }

        $pageUrl = $this->firstPageUrl($document);

        for ($page = 0; $page < self::MAX_PAGES && filled($pageUrl); $page++) {
            $pageDocument = $this->fetchJson($pageUrl);

            if ($pageDocument === null) {
                return false;
            }

            if ($this->itemsContainActor($pageDocument, $actorUri)) {
                return true;
            }

            $next = $pageDocument['next'] ?? null;
            $pageUrl = is_string($next) && $next !== '' ? $next : null;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function firstPageUrl(array $document): ?string
    {
        $first = $document['first'] ?? null;

        if (is_string($first) && $first !== '') {
            return $first;
        }

        if (is_array($first) && is_string($first['id'] ?? null)) {
            return $first['id'];
        }

        // CollectionPage gia' materiale (senza wrapper OrderedCollection).
        if (isset($document['orderedItems']) || isset($document['items'])) {
            return null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function itemsContainActor(array $document, string $actorUri): bool
    {
        $items = $document['orderedItems'] ?? $document['items'] ?? null;

        if (! is_array($items)) {
            return false;
        }

        $normalized = $this->normalizeUri($actorUri);

        foreach ($items as $item) {
            $itemUri = null;

            if (is_string($item)) {
                $itemUri = $item;
            } elseif (is_array($item) && is_string($item['id'] ?? null)) {
                $itemUri = $item['id'];
            }

            if ($itemUri !== null && $this->normalizeUri($itemUri) === $normalized) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchJson(string $url): ?array
    {
        try {
            $response = $this->httpClient->get($url, [
                'Accept' => 'application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
            ], $this->fetchSigner->resolve());
        } catch (SsrfViolationException $exception) {
            Log::channel('single')->info('federation.follow_confirm_fetch_blocked', [
                'url' => $url,
                'reason' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $document = $response->json();

        return is_array($document) ? $document : null;
    }

    private function normalizeUri(string $uri): string
    {
        return rtrim($uri, '/');
    }
}
