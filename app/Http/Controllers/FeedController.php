<?php

namespace App\Http\Controllers;

use App\Application\Queries\FeedQuery;
use App\Domain\Posts\Post;
use Illuminate\Contracts\View\View;

class FeedController extends Controller
{
    public function __construct(
        private readonly FeedQuery $feedQuery,
    ) {}

    public function index(): View
    {
        $user = auth()->user();
        $user->loadMissing(['profile', 'actor']);

        $posts = $this->feedQuery->forActor($user->actor);
        Post::annotateViewerState($posts->getCollection(), $user->actor);

        return view('feed.index', [
            'currentUser' => $user,
            'posts' => $posts,
        ]);
    }
}
