<?php

namespace App\Http\Controllers;

use App\Application\Queries\PopularHashtagsQuery;
use App\Domain\Posts\Hashtag;
use App\Domain\Posts\Post;
use Illuminate\Contracts\View\View;

class HashtagController extends Controller
{
    public function __construct(
        private readonly PopularHashtagsQuery $popularHashtags,
    ) {}

    public function index(): View
    {
        $hashtags = $this->popularHashtags->top(100);

        return view('hashtags.index', [
            'hashtags' => $hashtags,
        ]);
    }

    public function show(string $name): View
    {
        $normalized = Hashtag::normalize($name);
        $hashtag = Hashtag::query()->where('name', $normalized)->first();

        $viewer = auth()->user()?->actor;

        $posts = $hashtag !== null
            ? $hashtag->posts()
                ->with(Post::CARD_RELATIONS)
                ->where('status', Post::STATUS_PUBLISHED)
                ->visibleTo($viewer)
                ->orderByDesc('published_at')
                // Tiebreaker deterministico: senza di esso, con LIMIT/OFFSET,
                // piu' post pubblicati nello stesso secondo potrebbero finire
                // ordinati diversamente da una pagina all'altra (vedi
                // FeedQuery::TIEBREAKER_COLUMN per lo stesso problema altrove).
                ->orderByDesc('posts.id')
                ->paginate((int) config('openbook.feed.per_page'))
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
