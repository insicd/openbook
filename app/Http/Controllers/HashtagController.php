<?php

namespace App\Http\Controllers;

use App\Domain\Posts\Hashtag;
use App\Domain\Posts\Post;
use Illuminate\Contracts\View\View;

class HashtagController extends Controller
{
    public function show(string $name): View
    {
        $normalized = Hashtag::normalize($name);
        $hashtag = Hashtag::query()->where('name', $normalized)->first();

        $viewer = auth()->user()?->actor;

        $posts = $hashtag !== null
            ? $hashtag->posts()
                ->with(['actor.user.profile', 'media', 'hashtags'])
                ->where('status', Post::STATUS_PUBLISHED)
                ->visibleTo($viewer)
                ->orderByDesc('published_at')
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
