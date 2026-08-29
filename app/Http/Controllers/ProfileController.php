<?php

namespace App\Http\Controllers;

use App\Application\Queries\ActorActivityQuery;
use App\Application\Queries\ActorMediaQuery;
use App\Application\Queries\FeedCursor;
use App\Application\Queries\FeedQuery;
use App\Application\Queries\FollowListQuery;
use App\Application\Services\FollowManager;
use App\Application\Services\QuotedActorResolver;
use App\Domain\Accounts\User;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Actors\LocalActorResolver;
use App\Federation\Actors\LocalActorUrls;
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
        private readonly ActorMediaQuery $mediaQuery,
        private readonly ActorActivityQuery $activityQuery,
        private readonly FollowManager $followManager,
        private readonly FollowListQuery $followListQuery,
        private readonly LocalActorResolver $localActors,
        private readonly QuotedActorResolver $quotedActorResolver,
    ) {}

    /**
     * Pagina HTML "/@{username}". Le richieste ActivityPub vengono mandate
     * all'id canonico "/users/{username}" (evita mismatch Lemmy su /@ vs %40).
     */
    public function show(Request $request, string $username): View|JsonResponse|RedirectResponse
    {
        return $this->renderPersonProfile($request, $username, 'posts');
    }

    /**
     * Rullino fotografico del profilo: griglia di tutte le immagini allegate
     * ai post pubblicati dell'utente, visibili al visitatore.
     */
    public function photos(Request $request, string $username): View|RedirectResponse
    {
        return $this->renderPersonProfile($request, $username, 'photos');
    }

    /**
     * Cronologia di commenti/risposte e condivisioni visibili a questa
     * istanza. Il tab Post resta sui contenuti (e le condivisioni come card);
     * qui non si perdono i commenti fatti altrove.
     */
    public function activity(Request $request, string $username): View|RedirectResponse
    {
        return $this->renderPersonProfile($request, $username, 'activity');
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
     * Condividi il profilo locale in un messaggio privato: apre /messaggi
     * con la card gia' predisposta (link alla pagina /@{username}).
     */
    public function shareToUser(User $user): RedirectResponse
    {
        $viewer = auth()->user()->actor;
        $actor = $user->actor;

        abort_unless(
            $actor !== null && $this->quotedActorResolver->resolveForShare($viewer, $actor->id) !== null,
            404,
        );

        return redirect()->route('messages.index', ['share' => $actor->id]);
    }

    /**
     * "/users/{username}" e' l'identificatore ActivityPub canonico: con
     * negoziazione AP restituisce il documento Person/Group; per i browser
     * reindirizza alla pagina HTML ("/@..." o "/c/...").
     */
    public function redirectLegacy(Request $request, string $username): View|JsonResponse|RedirectResponse
    {
        $actor = $this->localActors->findByUsernameOrFail($username);

        if (ActivityPubNegotiation::wantsActivityPub($request)) {
            return ActivityPubNegotiation::response(ActorSerializer::serialize($actor));
        }

        if ($actor->isGroup()) {
            return redirect()->route('communities.show', $actor->preferred_username, 301);
        }

        return redirect()->route('profile.show', $actor->preferred_username, 301);
    }

    private function renderPersonProfile(Request $request, string $username, string $activeTab): View|JsonResponse|RedirectResponse
    {
        $actor = $this->localActors->findByUsernameForPublicProfile($username);

        abort_if($actor === null, 404);

        if ($activeTab === 'posts' && ActivityPubNegotiation::wantsActivityPub($request)) {
            return redirect()->away(
                LocalActorUrls::forUsername($actor->preferred_username, $actor->isGroup())['uri'],
                301,
            );
        }

        if ($actor->isGroup()) {
            return redirect()->route('communities.show', $actor->preferred_username);
        }

        $user = $actor->user;
        abort_if($user === null, 404);

        $user->loadMissing(['profile', 'actor.endpoints']);

        $profileSuspended = $actor->isSuspended();

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

        $posts = null;
        $media = null;
        $activity = null;

        if ($profileSuspended) {
            // Nessun contenuto sotto le tab: il profilo resta solo l'avviso.
        } elseif ($activeTab === 'photos') {
            $media = $this->mediaQuery->forActor($user->actor, $viewerActor);
        } elseif ($activeTab === 'activity') {
            $activity = $this->activityQuery->forActor($user->actor, $viewerActor, $request);
        } else {
            $posts = $this->feedQuery->forProfile(
                $user->actor,
                $viewerActor,
                FeedCursor::fromRequest($request),
            );
            Post::annotateViewerState($posts->getCollection(), $viewerActor);
        }

        return view('profile.show', [
            'profileUser' => $user,
            'profileSuspended' => $profileSuspended,
            'activeTab' => $activeTab,
            'posts' => $posts,
            'media' => $media,
            'activity' => $activity,
            'followersCount' => $followersCount,
            'followingCount' => $followingCount,
            'communitiesCount' => $communitiesCount,
            'isFollowing' => $isFollowing,
            'hasPendingRequest' => $hasPendingRequest,
            'pendingFollowRequests' => $pendingFollowRequests,
        ]);
    }
}
