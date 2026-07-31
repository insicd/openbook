<?php

namespace App\Http\Controllers\Federation;

use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\LocalActorResolver;
use App\Federation\Serialization\CollectionSerializer;
use App\Http\Controllers\Controller;
use App\Http\Support\ActivityPubNegotiation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Collezione pubblica dei follower di un Actor locale (Person o membri di un Group).
 */
final class FollowersController extends Controller
{
    public function __construct(
        private readonly LocalActorResolver $localActors,
    ) {}

    public function show(Request $request, string $username): JsonResponse
    {
        $actor = $this->localActors->findByUsernameOrFail($username);
        $actor->loadMissing('endpoints');
        $collectionId = $actor->endpoints?->followers ?? url("/users/{$actor->preferred_username}/followers");

        $query = Follow::query()->where('following_id', $actor->id)->where('status', Follow::STATUS_ACCEPTED);
        $totalItems = (clone $query)->count();

        $page = $request->query('page');

        if ($page === null) {
            return ActivityPubNegotiation::response(
                CollectionSerializer::collection($collectionId, $totalItems, $collectionId.'?page=1')
            );
        }

        $perPage = (int) config('openbook.feed.per_page', 20);
        $pageNumber = max(1, (int) $page);

        $items = (clone $query)
            ->with('follower')
            ->orderByDesc('accepted_at')
            ->forPage($pageNumber, $perPage)
            ->get()
            ->map(fn (Follow $follow) => $follow->follower->uri)
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
}
