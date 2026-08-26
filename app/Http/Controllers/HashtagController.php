<?php

namespace App\Http\Controllers;

use App\Application\Queries\FeedCursor;
use App\Application\Queries\FeedQuery;
use App\Application\Queries\PopularHashtagsQuery;
use App\Domain\Posts\Hashtag;
use App\Domain\Posts\Post;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class HashtagController extends Controller
{
    public function __construct(
        private readonly PopularHashtagsQuery $popularHashtags,
        private readonly FeedQuery $feedQuery,
    ) {}

    public function index(): View
    {
        $hashtags = $this->popularHashtags->top(100);

        return view('hashtags.index', [
            'hashtags' => $hashtags,
            'trendingDays' => max(1, (int) config('openbook.hashtags.trending_days', 7)),
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

        return view('hashtags.show', [
            'tagName' => $normalized,
            'posts' => $posts,
        ]);
    }
}
