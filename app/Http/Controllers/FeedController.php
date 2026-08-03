<?php

namespace App\Http\Controllers;

use App\Application\Queries\FeedQuery;
use App\Application\Queries\InstanceStaffQuery;
use App\Application\Queries\PopularRemoteActorsQuery;
use App\Application\Queries\SuggestedLocalActorsQuery;
use App\Domain\Communities\Community;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class FeedController extends Controller
{
    public function __construct(
        private readonly FeedQuery $feedQuery,
        private readonly InstanceStaffQuery $staffQuery,
        private readonly SuggestedLocalActorsQuery $localSuggestions,
        private readonly PopularRemoteActorsQuery $remoteSuggestions,
    ) {}

    public function index(Request $request): View
    {
        $user = auth()->user();
        $user->loadMissing(['profile', 'actor']);

        $posts = $this->feedQuery->forActor($user->actor);
        Post::annotateViewerState($posts->getCollection(), $user->actor);

        $quotedPost = $this->resolveQuotedPostForComposer($request, $user->actor);

        $composerCommunities = Community::query()
            ->with('actor')
            ->whereIn('actor_id', Follow::query()
                ->select('following_id')
                ->where('follower_id', $user->actor->id)
                ->where('status', Follow::STATUS_ACCEPTED))
            ->orderBy('slug')
            ->get();

        $welcomeKit = null;

        if ($posts->total() === 0) {
            $welcomeKit = $this->buildWelcomeKit($user->actor, $user->id);
        }

        return view('feed.index', [
            'currentUser' => $user,
            'posts' => $posts,
            'quotedPost' => $quotedPost,
            'composerCommunities' => $composerCommunities,
            'welcomeKit' => $welcomeKit,
        ]);
    }

    /**
     * Kit di benvenuto per la home vuota: staff, persone locali e
     * account remoti gia' noti all'istanza, senza duplicati.
     *
     * @return array{
     *     staff: Collection<int, \App\Domain\Accounts\User>,
     *     local: Collection<int, Actor>,
     *     remote: Collection<int, Actor>,
     * }
     */
    private function buildWelcomeKit(Actor $viewer, string $viewerUserId): array
    {
        $followedIds = Follow::query()
            ->where('follower_id', $viewer->id)
            ->pluck('following_id');

        $staff = $this->staffQuery->all()
            ->loadMissing(['actor', 'profile'])
            ->filter(function ($member) use ($viewerUserId, $followedIds): bool {
                if ($member->id === $viewerUserId || $member->actor === null) {
                    return false;
                }

                return ! $followedIds->contains($member->actor->id);
            })
            ->values();

        $staffActorIds = $staff->pluck('actor.id')->filter()->all();

        $local = $this->localSuggestions->forViewer(
            $viewer,
            SuggestedLocalActorsQuery::WELCOME_LIMIT,
            $staffActorIds,
        );

        $remote = $this->remoteSuggestions->forViewer($viewer, PopularRemoteActorsQuery::PREVIEW_LIMIT);

        return [
            'staff' => $staff,
            'local' => $local,
            'remote' => $remote,
        ];
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
