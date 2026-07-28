<?php

namespace App\Http\Controllers\Federation;

use App\Domain\Accounts\User;
use App\Domain\SocialGraph\Follow;
use App\Federation\Serialization\CollectionSerializer;
use App\Http\Controllers\Controller;
use App\Http\Support\ActivityPubNegotiation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Collezione pubblica degli account seguiti da un Actor locale, speculare a
 * {@see FollowersController}.
 */
final class FollowingController extends Controller
{
    public function show(Request $request, string $username): JsonResponse
    {
        $user = User::query()->where('username', mb_strtolower($username))->with('actor.endpoints')->first();

        if ($user === null || $user->actor === null) {
            abort(404);
        }

        $actor = $user->actor;
        $collectionId = $actor->endpoints?->following ?? url("/users/{$actor->preferred_username}/following");

        $query = Follow::query()->where('follower_id', $actor->id)->where('status', Follow::STATUS_ACCEPTED);
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
            ->with('following')
            ->orderByDesc('accepted_at')
            ->forPage($pageNumber, $perPage)
            ->get()
            ->map(fn (Follow $follow) => $follow->following->uri)
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
