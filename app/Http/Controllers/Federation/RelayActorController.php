<?php

namespace App\Http\Controllers\Federation;

use App\Application\Services\InstanceRelayActor;
use App\Domain\Comments\Comment;
use App\Domain\Events\Event;
use App\Domain\Events\EventComment;
use App\Domain\Federation\Relay;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\RelayActorUrls;
use App\Federation\Serialization\ActorSerializer;
use App\Federation\Serialization\CollectionSerializer;
use App\Federation\Serialization\NoteSerializer;
use App\Federation\Serialization\RelayActivitySerializer;
use App\Http\Controllers\Controller;
use App\Http\Support\ActivityPubNegotiation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class RelayActorController extends Controller
{
    public function show(InstanceRelayActor $relayActor): JsonResponse
    {
        return response()->json(
            ActorSerializer::serialize($relayActor->getOrCreate()),
            200,
            ['Content-Type' => 'application/activity+json; charset=utf-8'],
        );
    }

    public function outbox(Request $request, InstanceRelayActor $relayActor): JsonResponse
    {
        $actor = $relayActor->getOrCreate();
        $collectionId = RelayActorUrls::all()['outbox'];
        [$postQuery, $commentQuery, $eventQuery, $eventCommentQuery] = $this->publicLocalContentQueries();
        $totalItems = (clone $postQuery)->count()
            + (clone $commentQuery)->count()
            + (clone $eventQuery)->count()
            + (clone $eventCommentQuery)->count();

        if ($request->query('page') === null) {
            return ActivityPubNegotiation::response(
                CollectionSerializer::collection($collectionId, $totalItems, $collectionId.'?page=1')
            );
        }

        $perPage = (int) config('openbook.feed.per_page', 20);
        $pageNumber = max(1, (int) $request->query('page'));
        $rows = DB::query()
            ->fromSub(
                $postQuery->select(['id', 'published_at', DB::raw("'post' as item_type")])
                    ->unionAll($commentQuery->select(['id', DB::raw('created_at as published_at'), DB::raw("'comment' as item_type")]))
                    ->unionAll($eventQuery->select(['id', DB::raw('COALESCE(published_at, created_at) as published_at'), DB::raw("'event' as item_type")]))
                    ->unionAll($eventCommentQuery->select(['id', DB::raw('created_at as published_at'), DB::raw("'event_comment' as item_type")])),
                'relay_outbox_items',
            )
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->forPage($pageNumber, $perPage)
            ->get();

        $posts = Post::query()->whereIn('id', $rows->where('item_type', 'post')->pluck('id'))->get()->keyBy('id');
        $comments = Comment::query()->whereIn('id', $rows->where('item_type', 'comment')->pluck('id'))->get()->keyBy('id');
        $events = Event::query()->whereIn('id', $rows->where('item_type', 'event')->pluck('id'))->get()->keyBy('id');
        $eventComments = EventComment::query()->whereIn('id', $rows->where('item_type', 'event_comment')->pluck('id'))->get()->keyBy('id');

        $items = $rows->map(function (object $row) use ($actor, $posts, $comments, $events, $eventComments): ?array {
            $object = match ($row->item_type) {
                'post' => $posts->get($row->id),
                'comment' => $comments->get($row->id),
                'event' => $events->get($row->id),
                'event_comment' => $eventComments->get($row->id),
                default => null,
            };

            if ($object === null) {
                return null;
            }

            $uri = match (true) {
                $object instanceof Post, $object instanceof Comment => NoteSerializer::uriFor($object),
                default => $object->uri,
            };

            return RelayActivitySerializer::announceObject(
                $actor,
                $uri,
                $uri,
                $row->published_at !== null ? Carbon::parse($row->published_at)->toAtomString() : null,
            );
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

    public function followers(Request $request, InstanceRelayActor $relayActor): JsonResponse
    {
        $actor = $relayActor->getOrCreate();
        $query = Follow::query()
            ->where('following_id', $actor->id)
            ->where('status', Follow::STATUS_ACCEPTED)
            ->with('follower');

        return $this->actorCollection($request, RelayActorUrls::all()['followers'], $query, 'follower');
    }

    public function following(Request $request): JsonResponse
    {
        $query = Relay::query()
            ->where('protocol', Relay::PROTOCOL_ACTOR)
            ->where('state', Relay::STATE_ACCEPTED)
            ->where('receive_enabled', true)
            ->whereNotNull('actor_uri');
        $collectionId = RelayActorUrls::all()['following'];
        $totalItems = (clone $query)->count();

        if ($request->query('page') === null) {
            return ActivityPubNegotiation::response(
                CollectionSerializer::collection($collectionId, $totalItems, $collectionId.'?page=1')
            );
        }

        $perPage = (int) config('openbook.feed.per_page', 20);
        $pageNumber = max(1, (int) $request->query('page'));
        $items = (clone $query)
            ->orderByDesc('accepted_at')
            ->forPage($pageNumber, $perPage)
            ->pluck('actor_uri')
            ->filter()
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

    /**
     * @return array{Builder<Post>, Builder<Comment>, Builder<Event>, Builder<EventComment>}
     */
    private function publicLocalContentQueries(): array
    {
        $localActor = fn (Builder $query): Builder => $query->where('is_local', true);

        $posts = Post::query()
            ->where('visibility', Post::VISIBILITY_PUBLIC)
            ->where('status', Post::STATUS_PUBLISHED)
            ->whereHas('actor', $localActor)
            ->where(function (Builder $query): void {
                $query->whereNull('community_id')
                    ->orWhereHas('community', fn (Builder $community): Builder => $community->where('is_private', false));
            });
        $comments = Comment::query()
            ->where('status', Comment::STATUS_PUBLISHED)
            ->whereHas('actor', $localActor)
            ->whereHas('post', fn (Builder $post): Builder => $post
                ->where('visibility', Post::VISIBILITY_PUBLIC)
                ->where('status', Post::STATUS_PUBLISHED)
                ->where(function (Builder $query): void {
                    $query->whereNull('community_id')
                        ->orWhereHas('community', fn (Builder $community): Builder => $community->where('is_private', false));
                }));
        $events = Event::query()
            ->where('visibility', Event::VISIBILITY_PUBLIC)
            ->where('status', '!=', Event::STATUS_DELETED)
            ->whereHas('actor', $localActor);
        $eventComments = EventComment::query()
            ->where('status', EventComment::STATUS_PUBLISHED)
            ->whereHas('actor', $localActor)
            ->whereHas('event', fn (Builder $event): Builder => $event
                ->where('visibility', Event::VISIBILITY_PUBLIC)
                ->where('status', '!=', Event::STATUS_DELETED));

        return [$posts, $comments, $events, $eventComments];
    }

    /** @param Builder<Follow> $query */
    private function actorCollection(Request $request, string $collectionId, Builder $query, string $relation): JsonResponse
    {
        $totalItems = (clone $query)->count();

        if ($request->query('page') === null) {
            return ActivityPubNegotiation::response(
                CollectionSerializer::collection($collectionId, $totalItems, $collectionId.'?page=1')
            );
        }

        $perPage = (int) config('openbook.feed.per_page', 20);
        $pageNumber = max(1, (int) $request->query('page'));
        $items = (clone $query)
            ->orderByDesc('accepted_at')
            ->forPage($pageNumber, $perPage)
            ->get()
            ->map(fn (Follow $follow): ?string => $follow->{$relation}?->activityPubId())
            ->filter()
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
