<?php

namespace App\Http\Controllers\Concerns;

use App\Application\Queries\FollowListQuery;
use App\Application\Services\FollowManager;
use App\Federation\Actors\Actor;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/**
 * Vista condivisa dell'elenco follower/seguiti: la stessa pagina serve sia
 * un profilo locale ("/@utente/follower") sia un Actor remoto in cache
 * ("/attori/{id}/follower"), dato che {@see Actor} unifica gia' i due casi.
 */
trait RendersFollowLists
{
    /**
     * @param  Collection<int, Actor>|null  $remoteMembers
     */
    private function renderFollowList(
        FollowListQuery $followListQuery,
        FollowManager $followManager,
        Actor $owner,
        string $type,
        ?Collection $remoteMembers = null,
    ): View {
        $viewerActor = auth()->user()?->actor;
        $includePrivateHashtags = $type === 'following'
            && $owner->isLocal()
            && $viewerActor?->id === $owner->id;
        $paginator = $type === 'followers'
            ? $followListQuery->followers($owner)
            : $followListQuery->following($owner, includeHashtags: $includePrivateHashtags);

        $remoteMembers ??= collect();
        $statusActors = $paginator->getCollection()
            ->filter(fn ($item) => $item instanceof Actor)
            ->concat($remoteMembers)
            ->unique('id')
            ->values();
        $statusMap = $viewerActor !== null
            ? $followManager->statusMapFor($viewerActor, $statusActors)
            : [];

        $remoteTotal = $type === 'followers'
            ? $owner->followers_count
            : $owner->following_count;

        return view('follows.index', [
            'owner' => $owner,
            'type' => $type,
            'items' => $paginator,
            'remoteMembers' => $remoteMembers,
            'remotePreviewIncomplete' => is_int($remoteTotal) && $remoteTotal > $remoteMembers->count() + $paginator->total(),
            'viewerActor' => $viewerActor,
            'statusMap' => $statusMap,
        ]);
    }
}
