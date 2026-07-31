<?php

namespace App\Http\Controllers;

use App\Application\Queries\FeedQuery;
use App\Application\Services\CommunityMembershipService;
use App\Application\Services\CommunityModeratorManager;
use App\Application\Services\CommunityRegistrar;
use App\Application\Services\FollowManager;
use App\Domain\Accounts\User;
use App\Domain\Communities\Community;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Serialization\ActorSerializer;
use App\Http\Requests\Communities\StoreCommunityRequest;
use App\Http\Support\ActivityPubNegotiation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CommunityController extends Controller
{
    public function __construct(
        private readonly CommunityRegistrar $registrar,
        private readonly CommunityMembershipService $membership,
        private readonly CommunityModeratorManager $moderators,
        private readonly FeedQuery $feedQuery,
        private readonly FollowManager $followManager,
    ) {}

    public function index(): View
    {
        $communities = Community::query()
            ->with('actor')
            ->where('is_private', false)
            ->orderByDesc('members_count')
            ->orderBy('slug')
            ->paginate(20);

        return view('communities.index', [
            'communities' => $communities,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Community::class);

        return view('communities.create');
    }

    public function store(StoreCommunityRequest $request): RedirectResponse
    {
        Gate::authorize('create', Community::class);

        $community = $this->registrar->register($request->user(), $request->validated());

        return redirect()
            ->route('communities.show', $community)
            ->with('status', __('openbook.communities.created'));
    }

    public function show(Request $request, Community $community): View|JsonResponse
    {
        $community->loadMissing(['actor.endpoints', 'actor.key', 'owner.profile']);

        if (ActivityPubNegotiation::wantsActivityPub($request)) {
            return ActivityPubNegotiation::response(ActorSerializer::serialize($community->actor));
        }

        Gate::authorize('view', $community);

        $viewer = auth()->user();
        $viewerActor = $viewer?->actor;

        $isMember = $viewerActor !== null && $community->isMember($viewerActor);
        $hasPendingRequest = $viewerActor !== null
            && $this->followManager->hasPendingRequest($viewerActor, $community->actor);

        $pendingJoinRequests = collect();

        if ($viewer !== null && Gate::forUser($viewer)->allows('moderate', $community) && $community->is_private) {
            $pendingJoinRequests = Follow::query()
                ->where('following_id', $community->actor_id)
                ->where('status', Follow::STATUS_PENDING)
                ->with('follower.user.profile')
                ->latest('requested_at')
                ->get();
        }

        $posts = $this->feedQuery->forCommunity($community, $viewerActor);
        Post::annotateViewerState($posts->getCollection(), $viewerActor);

        $community->loadMissing('moderators.profile');

        return view('communities.show', [
            'community' => $community,
            'posts' => $posts,
            'isMember' => $isMember,
            'hasPendingRequest' => $hasPendingRequest,
            'pendingJoinRequests' => $pendingJoinRequests,
            'canPost' => $viewer !== null && Gate::forUser($viewer)->allows('post', $community),
            'canManageModerators' => $viewer !== null && Gate::forUser($viewer)->allows('manageModerators', $community),
        ]);
    }

    public function join(Community $community): RedirectResponse
    {
        Gate::authorize('join', $community);

        try {
            $this->membership->join(auth()->user()->actor, $community);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['community' => $exception->getMessage()]);
        }

        return back()->with('status', __('openbook.communities.joined'));
    }

    public function leave(Community $community): RedirectResponse
    {
        Gate::authorize('leave', $community);

        try {
            $this->membership->leave(auth()->user()->actor, $community);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['community' => $exception->getMessage()]);
        }

        return back()->with('status', __('openbook.communities.left'));
    }

    public function accept(Community $community, Follow $follow): RedirectResponse
    {
        Gate::authorize('moderate', $community);

        $this->membership->accept($community, $follow);

        return back()->with('status', __('openbook.communities.request_accepted'));
    }

    public function reject(Community $community, Follow $follow): RedirectResponse
    {
        Gate::authorize('moderate', $community);

        $this->membership->reject($community, $follow);

        return back()->with('status', __('openbook.communities.request_rejected'));
    }

    public function storeModerator(Request $request, Community $community): RedirectResponse
    {
        Gate::authorize('manageModerators', $community);

        $data = $request->validate([
            'username' => ['required', 'string', 'max:32'],
        ]);

        $this->moderators->add($community, $data['username']);

        return back()->with('status', __('openbook.communities.moderator_added'));
    }

    public function destroyModerator(Community $community, User $user): RedirectResponse
    {
        Gate::authorize('manageModerators', $community);

        $this->moderators->remove($community, $user);

        return back()->with('status', __('openbook.communities.moderator_removed'));
    }
}
