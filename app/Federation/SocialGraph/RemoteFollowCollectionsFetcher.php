<?php

namespace App\Federation\SocialGraph;

use App\Application\Services\DomainBlockManager;
use App\Federation\Actors\Actor;
use App\Federation\Actors\RemoteActorResolver;
use App\Federation\Fetch\FederationFetchSigner;
use App\Infrastructure\Security\Http\SafeHttpClient;
use App\Infrastructure\Security\Http\SsrfViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Recupera da un Actor remoto i conteggi autoritativi delle collection
 * followers/following (`totalItems`) e un campione della prima pagina
 * (`orderedItems`), con cache lunga per non martellare il server di origine.
 *
 * Non si pagina l'intera collection (puo' avere centinaia di migliaia di
 * voci). Le URI del campione restano in {@see RemoteCollectionMember},
 * separate dal grafo locale `follows`.
 */
final class RemoteFollowCollectionsFetcher
{
    public const MAX_MEMBERS = 40;

    public const MAX_RESOLVE_PER_REQUEST = 8;

    public function __construct(
        private readonly SafeHttpClient $httpClient,
        private readonly FederationFetchSigner $fetchSigner,
        private readonly RemoteActorResolver $remoteActorResolver,
        private readonly DomainBlockManager $domainBlocks,
    ) {}

    public function refreshIfStale(Actor $actor): void
    {
        if ($actor->isLocal() || $actor->isFeed()) {
            return;
        }

        $ttlHours = max(1, (int) config('openbook.federation.collections_cache_ttl_hours', 24));
        $withinTtl = $actor->collections_fetched_at !== null
            && $actor->collections_fetched_at->gt(Carbon::now()->subHours($ttlHours));

        if ($withinTtl) {
            return;
        }

        // Come l'outbox: si marca subito, cosi' un timeout remoto non
        // ripete la richiesta a ogni caricamento di pagina.
        $actor->forceFill(['collections_fetched_at' => now()])->saveQuietly();

        $actor->loadMissing('endpoints');
        $signingActor = $this->fetchSigner->resolve();

        $this->syncCollection(
            $actor,
            RemoteCollectionMember::COLLECTION_FOLLOWERS,
            $actor->endpoints?->followers,
            $signingActor,
        );
        $this->syncCollection(
            $actor,
            RemoteCollectionMember::COLLECTION_FOLLOWING,
            $actor->endpoints?->following,
            $signingActor,
        );
    }

    /**
     * Risolve un piccolo numero di URI del campione non ancora in cache
     * Actor: solo aprendo l'elenco, non il profilo (il profilo non deve
     * fare decine di GET).
     */
    public function hydrateUnresolvedMembers(Actor $actor, string $collection): void
    {
        if ($actor->isLocal() || $actor->isFeed()) {
            return;
        }

        $unresolved = RemoteCollectionMember::query()
            ->where('actor_id', $actor->id)
            ->where('collection', $collection)
            ->whereNull('member_actor_id')
            ->orderBy('position')
            ->limit(self::MAX_RESOLVE_PER_REQUEST)
            ->get();

        foreach ($unresolved as $member) {
            if ($this->domainBlocks->isBlockedUrl($member->member_uri)) {
                continue;
            }

            $resolved = $this->remoteActorResolver->resolveByUri($member->member_uri);

            if ($resolved !== null) {
                $member->forceFill(['member_actor_id' => $resolved->id])->saveQuietly();
            }
        }
    }

    private function syncCollection(Actor $actor, string $collection, ?string $url, ?Actor $signingActor): void
    {
        if (blank($url) || $this->domainBlocks->isBlockedUrl($url)) {
            return;
        }

        $root = $this->fetchJson($url, $signingActor);

        if ($root === null) {
            return;
        }

        $total = $this->totalItemsOf($root);
        $items = $this->itemUrisOf($root);

        if ($items === []) {
            $pageUrl = $this->firstPageUrl($root);

            if ($pageUrl !== null && $pageUrl !== $url && ! $this->domainBlocks->isBlockedUrl($pageUrl)) {
                $page = $this->fetchJson($pageUrl, $signingActor);

                if ($page !== null) {
                    $total ??= $this->totalItemsOf($page);
                    $items = $this->itemUrisOf($page);
                }
            }
        }

        $countColumn = $collection === RemoteCollectionMember::COLLECTION_FOLLOWERS
            ? 'followers_count'
            : 'following_count';

        if ($total !== null) {
            $actor->forceFill([$countColumn => $total])->saveQuietly();
        }

        $this->replaceMembers($actor, $collection, $items);
    }

    /**
     * @param  list<string>  $uris
     */
    private function replaceMembers(Actor $actor, string $collection, array $uris): void
    {
        $uris = array_values(array_unique(array_slice($uris, 0, self::MAX_MEMBERS)));

        RemoteCollectionMember::query()
            ->where('actor_id', $actor->id)
            ->where('collection', $collection)
            ->delete();

        if ($uris === []) {
            return;
        }

        $known = Actor::query()
            ->whereIn('uri', $uris)
            ->pluck('id', 'uri');

        foreach ($uris as $position => $uri) {
            RemoteCollectionMember::query()->create([
                'actor_id' => $actor->id,
                'collection' => $collection,
                'member_uri' => $uri,
                'member_actor_id' => $known[$uri] ?? null,
                'position' => $position,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<string>
     */
    private function itemUrisOf(array $document): array
    {
        $items = $document['orderedItems'] ?? $document['items'] ?? null;

        if (! is_array($items)) {
            return [];
        }

        $uris = [];

        foreach ($items as $item) {
            $uri = null;

            if (is_string($item) && $item !== '') {
                $uri = $item;
            } elseif (is_array($item) && is_string($item['id'] ?? null) && $item['id'] !== '') {
                $uri = $item['id'];
            }

            if ($uri === null || $this->domainBlocks->isBlockedUrl($uri)) {
                continue;
            }

            $uris[] = mb_substr($uri, 0, 255);

            if (count($uris) >= self::MAX_MEMBERS) {
                break;
            }
        }

        return $uris;
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

        if (is_array($first) && is_string($first['id'] ?? null) && $first['id'] !== '') {
            return $first['id'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function totalItemsOf(array $document): ?int
    {
        $value = $document['totalItems'] ?? null;

        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && is_numeric($value) && (int) $value >= 0) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchJson(string $url, ?Actor $signingActor): ?array
    {
        try {
            $response = $this->httpClient->get($url, [
                'Accept' => 'application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
            ], $signingActor);
        } catch (SsrfViolationException $exception) {
            Log::channel('single')->info('federation.collections_fetch_blocked', [
                'url' => $url,
                'reason' => $exception->getMessage(),
            ]);

            return null;
        } catch (\Throwable $exception) {
            Log::channel('single')->info('federation.collections_fetch_failed', [
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
}
