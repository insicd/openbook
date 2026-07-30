<?php

namespace App\Http\Controllers;

use App\Application\Queries\FeedQuery;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class FeedController extends Controller
{
    public function __construct(
        private readonly FeedQuery $feedQuery,
    ) {}

    public function index(Request $request): View
    {
        $user = auth()->user();
        $user->loadMissing(['profile', 'actor']);

        $posts = $this->feedQuery->forActor($user->actor);
        Post::annotateViewerState($posts->getCollection(), $user->actor);

        $quotedPost = $this->resolveQuotedPostForComposer($request, $user->actor);

        return view('feed.index', [
            'currentUser' => $user,
            'posts' => $posts,
            'quotedPost' => $quotedPost,
        ]);
    }

    /**
     * Post da citare nel composer: da query ?quote= oppure da old() dopo un
     * errore di validazione. Solo se ancora pubblicato e visibile all'autore.
     */
    private function resolveQuotedPostForComposer(Request $request, Actor $viewer): ?Post
    {
        $quotedId = $request->query('quote');

        if (! is_string($quotedId) || $quotedId === '') {
            $quotedId = old('quoted_post_id');
        }

        if (! is_string($quotedId) || $quotedId === '') {
            return null;
        }

        return Post::query()
            ->with(Post::CARD_RELATIONS)
            ->whereKey($quotedId)
            ->where('status', Post::STATUS_PUBLISHED)
            ->visibleTo($viewer)
            ->first();
    }
}
