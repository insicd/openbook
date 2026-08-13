<?php

namespace App\Http\Controllers;

use App\Application\Queries\ActorMediaQuery;
use App\Application\Queries\FeedCursor;
use App\Application\Queries\FeedQuery;
use App\Application\Queries\FollowListQuery;
use App\Application\Services\FollowManager;
use App\Domain\Feeds\FeedImporter;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Outbox\RemoteOutboxFetcher;
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
        private readonly FollowManager $followManager,
        private readonly FollowListQuery $followListQuery,
        private readonly RemoteOutboxFetcher $outboxFetcher,
        private readonly FeedImporter $feedImporter,
    ) {}

    public function show(Actor $actor, Request $request): View|RedirectResponse
    {
        return $this->renderRemoteProfile($actor, 'posts', $request);
    }

    public function photos(Actor $actor, Request $request): View|RedirectResponse
    {
        return $this->renderRemoteProfile($actor, 'photos', $request);
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

    private function renderRemoteProfile(Actor $actor, string $activeTab, Request $request): View|RedirectResponse
    {
        if ($actor->isLocal()) {
            return redirect()->route(
                $activeTab === 'photos' ? 'profile.photos' : 'profile.show',
                $actor->preferred_username,
            );
        }

        $actor->loadMissing('feedSource');

        if ($activeTab === 'posts') {
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

        $viewerActor = auth()->user()?->actor;

        $followersCount = Follow::query()->where('following_id', $actor->id)->where('status', 'accepted')->count();
        $followingCount = Follow::query()->where('follower_id', $actor->id)->where('status', 'accepted')->count();

        $isFollowing = false;
        $hasPendingRequest = false;

        if ($viewerActor !== null) {
            $isFollowing = $this->followManager->isFollowing($viewerActor, $actor);
            $hasPendingRequest = $this->followManager->hasPendingRequest($viewerActor, $actor);
        }

        $posts = null;
        $media = null;

        if ($activeTab === 'photos') {
            $media = $this->mediaQuery->forActor($actor, $viewerActor);
        } else {
            $posts = $this->feedQuery->forProfile($actor, $viewerActor, FeedCursor::fromRequest($request));
            Post::annotateViewerState($posts->getCollection(), $viewerActor);
        }

        return view('actors.show', [
            'profileActor' => $actor,
            'activeTab' => $activeTab,
            'posts' => $posts,
            'media' => $media,
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
}
