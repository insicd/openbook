<?php

namespace App\Http\Controllers\Federation;

use App\Domain\Feeds\FeedActorIdentity;
use App\Domain\Feeds\FeedActorUrls;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Federation\Serialization\ActivitySerializer;
use App\Federation\Serialization\ActorSerializer;
use App\Federation\Serialization\CollectionSerializer;
use App\Http\Controllers\Controller;
use App\Http\Support\ActivityPubNegotiation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Documento Actor e collezioni pubbliche di un contatto RSS/Atom, esposti
 * senza sessione cosi' le istanze remote possono dereferenziare attributedTo
 * e object di un Announce.
 */
final class FeedActorFederationController extends Controller
{
    public function __construct(
        private readonly FeedActorIdentity $identity,
    ) {}

    public function show(string $actor): JsonResponse
    {
        $actor = $this->feed($actor);

        return ActivityPubNegotiation::response(ActorSerializer::serialize($actor));
    }

    public function outbox(Request $request, string $actor): JsonResponse
    {
        $actor = $this->feed($actor);
        $collectionId = FeedActorUrls::for($actor)['outbox'];

        $query = Post::query()
            ->where('actor_id', $actor->id)
            ->where('status', Post::STATUS_PUBLISHED)
            ->whereIn('visibility', [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_UNLISTED]);

        $totalItems = (clone $query)->count();
        $page = $request->query('page');

        if ($page === null) {
            return ActivityPubNegotiation::response(
                CollectionSerializer::collection($collectionId, $totalItems, $collectionId.'?page=1')
            );
        }

        $perPage = (int) config('openbook.feed.per_page', 20);
        $pageNumber = max(1, (int) $page);
        $posts = (clone $query)
            ->with(['actor.endpoints', 'media.thumbnail', 'hashtags', 'mentions.actor', 'quotedPost', 'quotedActor', 'quotedEvent', 'community.actor', 'location'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->forPage($pageNumber, $perPage)
            ->get();

        $items = $posts
            ->map(fn (Post $post): array => ActivitySerializer::create($post))
            ->values()
            ->all();

        $hasMore = ($pageNumber * $perPage) < $totalItems;

        return ActivityPubNegotiation::response(CollectionSerializer::page(
            $collectionId.'?page='.$pageNumber,
            $collectionId,
            $items,
            $hasMore ? $collectionId.'?page='.($pageNumber + 1) : null,
            $pageNumber > 1 ? $collectionId.'?page='.($pageNumber - 1) : null,
        ));
    }

    public function followers(string $actor): JsonResponse
    {
        return $this->emptyCollection($actor, 'followers');
    }

    public function following(string $actor): JsonResponse
    {
        return $this->emptyCollection($actor, 'following');
    }

    private function emptyCollection(string $actorId, string $collection): JsonResponse
    {
        $actor = $this->feed($actorId);
        $collectionId = FeedActorUrls::for($actor)[$collection];

        return ActivityPubNegotiation::response(
            CollectionSerializer::collection($collectionId, 0, $collectionId.'?page=1')
        );
    }

    private function feed(string $actorId): Actor
    {
        $actor = Actor::query()->find($actorId);

        abort_unless($actor !== null && $actor->isFeed() && $actor->isActive(), 404);

        return $this->identity->ensure($actor);
    }
}
