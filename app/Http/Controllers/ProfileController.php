<?php

namespace App\Http\Controllers;

use App\Application\Queries\FeedQuery;
use App\Application\Queries\FollowListQuery;
use App\Application\Services\FollowManager;
use App\Domain\Accounts\User;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Actors\LocalActorResolver;
use App\Federation\Serialization\ActorSerializer;
use App\Http\Controllers\Concerns\RendersFollowLists;
use App\Http\Support\ActivityPubNegotiation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    use RendersFollowLists;

    public function __construct(
        private readonly FeedQuery $feedQuery,
        private readonly FollowManager $followManager,
        private readonly FollowListQuery $followListQuery,
        private readonly LocalActorResolver $localActors,
    ) {}

    /**
     * Identificatore canonico "/@{username}": Person (HTML profilo) o Group
     * (redirect HTML a /c/{slug}, documento ActivityPub "Group" se negoziato).
     */
    public function show(Request $request, string $username): View|JsonResponse|RedirectResponse
    {
        $actor = $this->localActors->findByUsernameOrFail($username);

        if ($actor->isGroup()) {
            if (ActivityPubNegotiation::wantsActivityPub($request)) {
                return ActivityPubNegotiation::response(ActorSerializer::serialize($actor));
            }

            return redirect()->route('communities.show', $actor->preferred_username);
        }

        $user = $actor->user;
        abort_if($user === null, 404);

        $user->loadMissing(['profile', 'actor.endpoints']);

        if (ActivityPubNegotiation::wantsActivityPub($request)) {
            return ActivityPubNegotiation::response(ActorSerializer::serialize($user->actor));
        }

        $viewerActor = auth()->user()?->actor;

        $followersCount = Follow::query()->where('following_id', $user->actor->id)->where('status', 'accepted')->count();
        $followingCount = Follow::query()->where('follower_id', $user->actor->id)->where('status', 'accepted')->count();

        $communitiesCount = Follow::query()
            ->where('follower_id', $user->actor->id)
            ->where('status', Follow::STATUS_ACCEPTED)
            ->whereHas('following', fn ($query) => $query->where('type', Actor::TYPE_GROUP))
            ->count();

        $isFollowing = false;
        $hasPendingRequest = false;

        if ($viewerActor !== null && $viewerActor->id !== $user->actor->id) {
            $isFollowing = $this->followManager->isFollowing($viewerActor, $user->actor);
            $hasPendingRequest = $this->followManager->hasPendingRequest($viewerActor, $user->actor);
        }

        $pendingFollowRequests = collect();

        if ($viewerActor !== null && $viewerActor->id === $user->actor->id && $user->actor->manually_approves_followers) {
            $pendingFollowRequests = Follow::query()
                ->where('following_id', $user->actor->id)
                ->where('status', Follow::STATUS_PENDING)
                ->with('follower.user.profile')
                ->latest('requested_at')
                ->get();
        }

        $posts = $this->feedQuery->forProfile($user->actor, $viewerActor);
        Post::annotateViewerState($posts->getCollection(), $viewerActor);

        return view('profile.show', [
            'profileUser' => $user,
            'posts' => $posts,
            'followersCount' => $followersCount,
            'followingCount' => $followingCount,
            'communitiesCount' => $communitiesCount,
            'isFollowing' => $isFollowing,
            'hasPendingRequest' => $hasPendingRequest,
            'pendingFollowRequests' => $pendingFollowRequests,
        ]);
    }

    public function followers(User $user): View
    {
        return $this->renderFollowList($this->followListQuery, $this->followManager, $user->actor, 'followers');
    }

    public function following(User $user): View
    {
        return $this->renderFollowList($this->followListQuery, $this->followManager, $user->actor, 'following');
    }

    /**
     * L'URL "/users/{username}" non e' canonico: effettua un redirect
     * permanente verso "/@{username}", che e' l'identificatore usato sia
     * per la pagina HTML sia per il documento ActivityPub.
     */
    public function redirectLegacy(string $username): RedirectResponse
    {
        $actor = $this->localActors->findByUsernameOrFail($username);

        return redirect()->to($actor->uri, 301);
    }
}
