<?php

namespace App\Http\Controllers;

use App\Application\Queries\FeedQuery;
use App\Application\Queries\FollowListQuery;
use App\Application\Services\FollowManager;
use App\Domain\Accounts\User;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
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
    ) {}

    /**
     * Pagina profilo pubblica, identificatore canonico dell'Actor locale: la
     * stessa rotta serve sia l'HTML sia, tramite content negotiation
     * (Accept: application/activity+json o application/ld+json), il
     * documento ActivityPub "Person" (sezione 8 del design).
     */
    public function show(Request $request, User $user): View|JsonResponse
    {
        $user->loadMissing(['profile', 'actor.endpoints']);

        if (ActivityPubNegotiation::wantsActivityPub($request)) {
            return ActivityPubNegotiation::response(ActorSerializer::serialize($user->actor));
        }

        $viewerActor = auth()->user()?->actor;

        $followersCount = Follow::query()->where('following_id', $user->actor->id)->where('status', 'accepted')->count();
        $followingCount = Follow::query()->where('follower_id', $user->actor->id)->where('status', 'accepted')->count();

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
     * per la pagina HTML sia (in futuro) per il documento ActivityPub.
     */
    public function redirectLegacy(string $username): RedirectResponse
    {
        $user = User::query()->where('username', mb_strtolower($username))->firstOrFail();

        return redirect()->route('profile.show', $user->username, status: 301);
    }
}
