<?php

namespace App\Http\Controllers\Federation;

use App\Domain\Events\Event;
use App\Federation\Actors\LocalActorResolver;
use App\Federation\Actors\LocalActorUrls;
use App\Federation\Serialization\CollectionSerializer;
use App\Federation\Serialization\EventSerializer;
use App\Http\Controllers\Controller;
use App\Http\Support\ActivityPubNegotiation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Collection degli eventi pubblici e non elencati di un Actor locale. */
final class EventsCollectionController extends Controller
{
    public function __construct(
        private readonly LocalActorResolver $localActors,
    ) {}

    public function show(Request $request, string $username): JsonResponse
    {
        $actor = $this->localActors->findByUsernameOrFail($username);
        $collectionId = LocalActorUrls::forUsername($actor->preferred_username, $actor->isGroup())['events'];
        $query = Event::query()
            ->where('actor_id', $actor->id)
            ->whereIn('visibility', [Event::VISIBILITY_PUBLIC, Event::VISIBILITY_UNLISTED])
            ->where('status', '!=', Event::STATUS_DELETED);
        $totalItems = (clone $query)->count();
        $page = $request->query('page');

        if ($page === null) {
            return ActivityPubNegotiation::response(
                CollectionSerializer::collection($collectionId, $totalItems, $collectionId.'?page=1')
            );
        }

        $perPage = (int) config('openbook.feed.per_page', 20);
        $pageNumber = max(1, (int) $page);
        $events = $query
            ->with(['actor.endpoints', 'location', 'media.thumbnail', 'hashtags', 'mentions.actor'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->forPage($pageNumber, $perPage)
            ->get();
        $items = $events->map(fn (Event $event): array => EventSerializer::serialize($event))->all();
        $hasMore = ($pageNumber * $perPage) < $totalItems;

        return ActivityPubNegotiation::response(CollectionSerializer::page(
            $collectionId.'?page='.$pageNumber,
            $collectionId,
            $items,
            $hasMore ? $collectionId.'?page='.($pageNumber + 1) : null,
            $pageNumber > 1 ? $collectionId.'?page='.($pageNumber - 1) : null,
        ));
    }
}
