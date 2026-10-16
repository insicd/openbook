<?php

namespace App\Http\Controllers;

use App\Application\Queries\FeedCursor;
use App\Application\Queries\FeedQuery;
use App\Application\Queries\FeedWelcomeQuery;
use App\Domain\Communities\Community;
use App\Domain\Posts\PendingPostPublication;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class FeedController extends Controller
{
    public function __construct(
        private readonly FeedQuery $feedQuery,
        private readonly FeedWelcomeQuery $welcomeQuery,
    ) {}

    public function index(Request $request): View
    {
        $user = auth()->user();
        $user->loadMissing(['profile', 'actor']);

        if ($request->ajax()) {
            $cursor = FeedCursor::fromRequest($request);
            $posts = $this->feedQuery->forActor($user->actor, $cursor);
            Post::annotateViewerState($posts->getCollection(), $user->actor);

            return view('posts._feed', [
                'posts' => $posts,
                'emptyMessage' => __('openbook.feed.empty'),
                'homeFeed' => true,
                'fragment' => true,
                'welcomeKit' => $cursor === null
                    ? $this->welcomeQuery->forViewer($user->actor, $user->id)
                    : null,
            ]);
        }

        $quotedPost = $this->resolveQuotedPostForComposer($request, $user->actor);

        $composerCommunities = Community::query()
            ->with('actor')
            ->whereIn('actor_id', Follow::query()
                ->select('following_id')
                ->where('follower_id', $user->actor->id)
                ->where('status', Follow::STATUS_ACCEPTED))
            ->orderBy('slug')
            ->get();

        return view('feed.index', [
            'currentUser' => $user,
            'quotedPost' => $quotedPost,
            'composerCommunities' => $composerCommunities,
            'pendingPublications' => config('openbook.video.enabled', false)
                ? PendingPostPublication::query()
                    ->where('actor_id', $user->actor->id)
                    ->whereIn('status', [
                        PendingPostPublication::STATUS_PENDING,
                        PendingPostPublication::STATUS_PROCESSING,
                        PendingPostPublication::STATUS_FAILED,
                    ])
                    ->latest()
                    ->get()
                : collect(),
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
