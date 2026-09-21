<?php

namespace App\Http\Controllers\Federation;

use App\Domain\Events\Event;
use App\Domain\Posts\Post;
use App\Federation\Actors\LocalActorResolver;
use App\Federation\Serialization\ActivitySerializer;
use App\Federation\Serialization\CollectionSerializer;
use App\Http\Controllers\Controller;
use App\Http\Support\ActivityPubNegotiation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Outbox pubblico di un Actor locale (Person o Group).
 */
final class OutboxController extends Controller
{
    public function __construct(
        private readonly LocalActorResolver $localActors,
    ) {}

    public function show(Request $request, string $username): JsonResponse
    {
        $actor = $this->localActors->findByUsernameOrFail($username);
        $actor->loadMissing('endpoints', 'community');
        $collectionId = $actor->endpoints?->outbox ?? url("/users/{$actor->preferred_username}/outbox");

        $query = Post::query()
            ->whereIn('visibility', [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_UNLISTED])
            ->where('status', Post::STATUS_PUBLISHED)
            ->where(function ($query) {
                $query->whereNull('community_id')
                    ->orWhereHas('community', fn ($community) => $community->where('is_private', false));
            });

        if ($actor->isGroup() && $actor->community !== null) {
            $query->where('community_id', $actor->community->id);

            // Outbox pubblico del Group: le community private non espongono
            // la cronologia (i membri la ricevono via consegna / wall HTML).
            if ($actor->community->is_private) {
                $query->whereRaw('0 = 1');
            }
        } else {
            $query->where('actor_id', $actor->id);
        }

        $eventQuery = Event::query()
            ->where('actor_id', $actor->id)
            ->whereIn('visibility', [Event::VISIBILITY_PUBLIC, Event::VISIBILITY_UNLISTED])
            ->where('status', '!=', Event::STATUS_DELETED);

        $totalItems = (clone $query)->count() + (clone $eventQuery)->count();

        $page = $request->query('page');

        if ($page === null) {
            return ActivityPubNegotiation::response(
                CollectionSerializer::collection($collectionId, $totalItems, $collectionId.'?page=1')
            );
        }

        $perPage = (int) config('openbook.feed.per_page', 20);
        $pageNumber = max(1, (int) $page);

        $postItems = (clone $query)->select([
            'id',
            'published_at',
            DB::raw("'post' as item_type"),
        ]);
        $eventItems = (clone $eventQuery)->select([
            'id',
            'published_at',
            DB::raw("'event' as item_type"),
        ]);
        $rows = DB::query()
            ->fromSub($postItems->unionAll($eventItems), 'outbox_items')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->forPage($pageNumber, $perPage)
            ->get();
        $posts = Post::query()
            ->with(['actor.endpoints', 'media.thumbnail', 'hashtags', 'mentions.actor', 'quotedPost', 'quotedActor', 'quotedEvent', 'community.actor', 'location'])
            ->whereIn('id', $rows->where('item_type', 'post')->pluck('id'))
            ->get()
            ->keyBy('id');
        $events = Event::query()
            ->with(['actor.endpoints', 'location', 'media.thumbnail', 'hashtags', 'mentions.actor'])
            ->whereIn('id', $rows->where('item_type', 'event')->pluck('id'))
            ->get()
            ->keyBy('id');
        $items = $rows->map(function (object $row) use ($posts, $events): ?array {
            $object = $row->item_type === 'event'
                ? $events->get($row->id)
                : $posts->get($row->id);

            return $object !== null ? ActivitySerializer::create($object) : null;
        })->filter()->values()->all();

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
