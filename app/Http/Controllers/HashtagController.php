<?php

namespace App\Http\Controllers;

use App\Application\Queries\FeedCursor;
use App\Application\Queries\FeedQuery;
use App\Application\Queries\PopularHashtagsQuery;
use App\Domain\Events\Event;
use App\Domain\Posts\Hashtag;
use App\Domain\Posts\Post;
use App\Support\CompactNumber;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HashtagController extends Controller
{
    public function __construct(
        private readonly PopularHashtagsQuery $popularHashtags,
        private readonly FeedQuery $feedQuery,
    ) {}

    public function index(): View
    {
        $this->popularHashtags->invalidateSidebar();
        $hashtags = $this->popularHashtags->top(100);
        $this->popularHashtags->primeSidebar($hashtags);
        $viewerActorId = auth()->user()?->actor?->id;
        $followedHashtagIds = $viewerActorId !== null
            ? DB::table('hashtag_follows')
                ->where('actor_id', $viewerActorId)
                ->whereIn('hashtag_id', $hashtags->pluck('id'))
                ->pluck('hashtag_id')
                ->all()
            : [];

        return view('hashtags.index', [
            'hashtags' => $hashtags,
            'trendingDays' => max(1, (int) config('openbook.hashtags.trending_days', 7)),
            'followedHashtagIds' => $followedHashtagIds,
        ]);
    }

    public function sidebar(): JsonResponse
    {
        $hashtags = $this->popularHashtags->sidebar();

        return response()->json([
            'hashtags' => $hashtags->take(PopularHashtagsQuery::SIDEBAR_LIMIT)
                ->map(static function (Hashtag $hashtag): array {
                    $count = (int) $hashtag->usage_count;

                    return [
                        'name' => $hashtag->name,
                        'url' => route('hashtags.show', $hashtag->name),
                        'uses' => trans_choice('openbook.sidebar.hashtag_uses', $count, [
                            'count' => CompactNumber::format($count),
                        ]),
                    ];
                })
                ->values(),
            'has_more' => $hashtags->count() > PopularHashtagsQuery::SIDEBAR_LIMIT,
        ]);
    }

    public function show(Request $request, string $name): View
    {
        $normalized = Hashtag::normalize($name);
        $hashtag = Hashtag::query()->where('name', $normalized)->first();

        $viewer = auth()->user()?->actor;

        $posts = $hashtag !== null
            ? $this->feedQuery->paginatePublishedQuery(
                $hashtag->posts()
                    ->with(Post::CARD_RELATIONS)
                    ->where('status', Post::STATUS_PUBLISHED)
                    ->visibleTo($viewer),
                FeedCursor::fromRequest($request),
            )
            : null;

        if ($posts !== null) {
            Post::annotateViewerState($posts->getCollection(), $viewer);
        }

        $events = $hashtag !== null
            ? $hashtag->events()
                ->with(['actor.user.profile', 'location', 'media.thumbnail', 'attributions.user.profile'])
                ->visibleTo($viewer)
                ->where(function (Builder $query) use ($viewer): void {
                    $query->where('visibility', Event::VISIBILITY_PUBLIC);

                    if ($viewer !== null) {
                        $query->orWhere('visibility', Event::VISIBILITY_FOLLOWERS);
                    }
                })
                ->whereIn('status', [Event::STATUS_SCHEDULED, Event::STATUS_TENTATIVE, Event::STATUS_POSTPONED])
                ->where(function (Builder $query): void {
                    $defaultHours = max(1, (int) config('openbook.events.default_duration_hours', 12));

                    $query->where('end_at', '>', now())
                        ->orWhere(function (Builder $query) use ($defaultHours): void {
                            $query->whereNull('end_at')->where('start_at', '>', now()->subHours($defaultHours));
                        });
                })
                ->orderBy('start_at')
                ->orderBy('name')
                ->limit(6)
                ->get()
            : collect();

        return view('hashtags.show', [
            'tagName' => $normalized,
            'posts' => $posts,
            'events' => $events,
            'isFollowing' => $viewer !== null && $hashtag !== null
                && DB::table('hashtag_follows')
                    ->where('actor_id', $viewer->id)
                    ->where('hashtag_id', $hashtag->id)
                    ->exists(),
        ]);
    }
}
