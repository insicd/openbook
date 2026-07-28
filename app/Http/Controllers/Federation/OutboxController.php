<?php

namespace App\Http\Controllers\Federation;

use App\Domain\Accounts\User;
use App\Domain\Posts\Post;
use App\Federation\Serialization\CollectionSerializer;
use App\Federation\Serialization\NoteSerializer;
use App\Http\Controllers\Controller;
use App\Http\Support\ActivityPubNegotiation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Outbox pubblico di un Actor locale (sezione 21 del design): in questa fase
 * viene generato dinamicamente a partire dai post pubblici gia' esistenti,
 * ciascuno avvolto in un'attivita' "Create". Una tabella "activities"
 * persistita, necessaria per la consegna reale, arriva con la Fase 4.
 */
final class OutboxController extends Controller
{
    public function show(Request $request, string $username): JsonResponse
    {
        $user = User::query()->where('username', mb_strtolower($username))->with('actor.endpoints')->first();

        if ($user === null || $user->actor === null) {
            abort(404);
        }

        $actor = $user->actor;
        $collectionId = $actor->endpoints?->outbox ?? url("/users/{$actor->preferred_username}/outbox");

        $query = Post::query()
            ->where('actor_id', $actor->id)
            ->whereIn('visibility', [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_UNLISTED])
            ->where('status', Post::STATUS_PUBLISHED);

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
            ->orderByDesc('published_at')
            ->forPage($pageNumber, $perPage)
            ->get();

        $items = $posts->map(function (Post $post) use ($actor) {
            $note = NoteSerializer::forPost($post);

            return [
                'id' => $note['id'].'/activity',
                'type' => 'Create',
                'actor' => $actor->uri,
                'published' => $note['published'],
                'to' => $note['to'] ?? [],
                'cc' => $note['cc'] ?? [],
                'object' => $note,
            ];
        })->values()->all();

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
