<?php

namespace App\Http\Controllers;

use App\Application\Queries\ActorActivityQuery;
use App\Application\Queries\ActorMediaQuery;
use App\Application\Queries\FeedCursor;
use App\Application\Queries\FeedQuery;
use App\Application\Queries\FollowListQuery;
use App\Application\Services\FollowManager;
use App\Application\Services\QuotedActorResolver;
use App\Domain\Feeds\FeedImporter;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Outbox\RemoteOutboxFetcher;
use App\Federation\SocialGraph\RemoteFollowCollectionsFetcher;
use App\Http\Controllers\Concerns\RendersFollowLists;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
        private readonly ActorMediaQuery $mediaQuery,
        private readonly ActorActivityQuery $activityQuery,
        private readonly FollowManager $followManager,
        private readonly FollowListQuery $followListQuery,
        private readonly RemoteOutboxFetcher $outboxFetcher,
        private readonly RemoteFollowCollectionsFetcher $collectionsFetcher,
        private readonly FeedImporter $feedImporter,
        private readonly QuotedActorResolver $quotedActorResolver,
    ) {}

    public function show(Actor $actor, Request $request): View|RedirectResponse
    {
        return $this->renderRemoteProfile($actor, 'posts', $request);
    }

    public function photos(Actor $actor, Request $request): View|RedirectResponse
    {
        return $this->renderRemoteProfile($actor, 'photos', $request);
    }

    public function activity(Actor $actor, Request $request): View|RedirectResponse
    {
        return $this->renderRemoteProfile($actor, 'activity', $request);
    }

    public function followers(Actor $actor): View|RedirectResponse
    {
        if ($actor->isLocal()) {
            return redirect()->route('profile.followers', $actor->preferred_username);
        }

        return $this->renderRemoteFollowList($actor, 'followers');
    }

    public function following(Actor $actor): View|RedirectResponse
    {
        if ($actor->isLocal()) {
            return redirect()->route('profile.following', $actor->preferred_username);
        }

        return $this->renderRemoteFollowList($actor, 'following');
    }

    /**
     * Condividi un Actor remoto in un messaggio privato. Il destinatario
     * riceve la pagina locale /attori/{id} (con pulsante Segui), non l'URI
     * ActivityPub del server di origine.
     */
    public function shareToUser(Actor $actor): RedirectResponse
    {
        if ($actor->isLocal()) {
            abort_unless($actor->user !== null, 404);

            return redirect()->route('profiles.share_to_user', $actor->user);
        }

        $viewer = auth()->user()->actor;

        abort_unless($this->quotedActorResolver->resolveForShare($viewer, $actor->id) !== null, 404);

        return redirect()->route('messages.index', ['share' => $actor->id]);
    }

    private function renderRemoteProfile(Actor $actor, string $activeTab, Request $request): View|RedirectResponse
    {
        if ($actor->isLocal()) {
            $route = match ($activeTab) {
                'photos' => 'profile.photos',
                'activity' => 'profile.activity',
                default => 'profile.show',
            };

            return redirect()->route($route, $actor->preferred_username);
        }

        if ($activeTab === 'activity' && ($actor->isGroup() || $actor->isFeed())) {
            return redirect()->route('actors.show', $actor);
        }

        $actor->loadMissing('feedSource');

        if ($activeTab === 'posts' || $activeTab === 'activity') {
            try {
                if ($actor->isFeed()) {
                    $this->feedImporter->import($actor);
                } else {
                    $this->outboxFetcher->fetchRecentPosts($actor);
                }
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        try {
            $this->collectionsFetcher->refreshIfStale($actor);
        } catch (\Throwable $exception) {
            report($exception);
        }

        $actor = $actor->fresh() ?? $actor;
        $actor->loadMissing('feedSource');

        $viewerActor = auth()->user()?->actor;

        $localFollowersCount = Follow::query()->where('following_id', $actor->id)->where('status', 'accepted')->count();
        $localFollowingCount = Follow::query()->where('follower_id', $actor->id)->where('status', 'accepted')->count();
        $followersCount = $actor->followers_count ?? $localFollowersCount;
        $followingCount = $actor->following_count ?? $localFollowingCount;

        $isFollowing = false;
        $hasPendingRequest = false;

        if ($viewerActor !== null) {
            $isFollowing = $this->followManager->isFollowing($viewerActor, $actor);
            $hasPendingRequest = $this->followManager->hasPendingRequest($viewerActor, $actor);
        }

        $posts = null;
        $media = null;
        $activity = null;

        if ($activeTab === 'photos') {
            $media = $this->mediaQuery->forActor($actor, $viewerActor);
        } elseif ($activeTab === 'activity') {
            $activity = $this->activityQuery->forActor($actor, $viewerActor, $request);
        } else {
            $posts = $this->feedQuery->forProfile($actor, $viewerActor, FeedCursor::fromRequest($request));
            Post::annotateViewerState($posts->getCollection(), $viewerActor);
        }

        return view('actors.show', [
            'profileActor' => $actor,
            'activeTab' => $activeTab,
            'posts' => $posts,
            'media' => $media,
            'activity' => $activity,
            'followersCount' => $followersCount,
            'followingCount' => $followingCount,
            'isFollowing' => $isFollowing,
            'hasPendingRequest' => $hasPendingRequest,
            'emptyPostsMessage' => $this->emptyPostsMessage($actor, $isFollowing, $hasPendingRequest),
        ]);
    }

    private function emptyPostsMessage(Actor $actor, bool $isFollowing, bool $hasPendingRequest): string
    {
        if ($actor->isFeed()) {
            return __('openbook.actors.feed_empty');
        }

        if ($actor->isGroup()) {
            return __('openbook.communities.wall_empty');
        }

        if ($actor->isThreads()) {
            if ($isFollowing) {
                return __('openbook.actors.threads_waiting_for_posts');
            }

            if ($hasPendingRequest) {
                return __('openbook.actors.threads_pending_follow');
            }

            return __('openbook.actors.threads_outbox_unavailable');
        }

        return __('openbook.profile.no_posts_yet');
    }

    private function renderRemoteFollowList(Actor $actor, string $type): View
    {
        try {
            $this->collectionsFetcher->refreshIfStale($actor);
            $this->collectionsFetcher->hydrateUnresolvedMembers($actor, $type);
        } catch (\Throwable $exception) {
            report($exception);
        }

        $actor = $actor->fresh() ?? $actor;
        $remoteMembers = $this->followListQuery->remotePreview($actor, $type);

        return $this->renderFollowList(
            $this->followListQuery,
            $this->followManager,
            $actor,
            $type,
            $remoteMembers,
        );
    }
}
