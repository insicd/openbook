<?php

namespace App\Http\Controllers;

use App\Application\Queries\FeedQuery;
use App\Application\Queries\FollowListQuery;
use App\Application\Services\FollowManager;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Http\Controllers\Concerns\RendersFollowLists;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Pagina profilo per un Actor *remoto* in cache locale: a differenza di
 * "/@{username}" (identificatore canonico degli Actor locali, anche per la
 * negoziazione ActivityPub) questa e' una pagina puramente di comodo per la
 * navigazione dell'interfaccia. Un Actor remoto non ha mai un identificatore
 * canonico su questa istanza: chi volesse il documento ActivityPub originale
 * deve recuperarlo dal suo "uri" reale, sul server di appartenenza.
 */
class ActorProfileController extends Controller
{
    use RendersFollowLists;

    public function __construct(
        private readonly FeedQuery $feedQuery,
        private readonly FollowManager $followManager,
        private readonly FollowListQuery $followListQuery,
    ) {}

    public function show(Actor $actor): View|RedirectResponse
    {
        if ($actor->isLocal()) {
            return redirect()->route('profile.show', $actor->preferred_username);
        }

        $viewerActor = auth()->user()?->actor;

        $followersCount = Follow::query()->where('following_id', $actor->id)->where('status', 'accepted')->count();
        $followingCount = Follow::query()->where('follower_id', $actor->id)->where('status', 'accepted')->count();

        $isFollowing = false;
        $hasPendingRequest = false;

        if ($viewerActor !== null) {
            $isFollowing = $this->followManager->isFollowing($viewerActor, $actor);
            $hasPendingRequest = $this->followManager->hasPendingRequest($viewerActor, $actor);
        }

        $posts = $this->feedQuery->forProfile($actor, $viewerActor);
        Post::annotateViewerState($posts->getCollection(), $viewerActor);

        return view('actors.show', [
            'profileActor' => $actor,
            'posts' => $posts,
            'followersCount' => $followersCount,
            'followingCount' => $followingCount,
            'isFollowing' => $isFollowing,
            'hasPendingRequest' => $hasPendingRequest,
        ]);
    }

    public function followers(Actor $actor): View|RedirectResponse
    {
        if ($actor->isLocal()) {
            return redirect()->route('profile.followers', $actor->preferred_username);
        }

        return $this->renderFollowList($this->followListQuery, $this->followManager, $actor, 'followers');
    }

    public function following(Actor $actor): View|RedirectResponse
    {
        if ($actor->isLocal()) {
            return redirect()->route('profile.following', $actor->preferred_username);
        }

        return $this->renderFollowList($this->followListQuery, $this->followManager, $actor, 'following');
    }
}
