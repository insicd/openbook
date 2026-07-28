<?php

namespace App\Http\Controllers\Concerns;

use App\Application\Queries\FollowListQuery;
use App\Application\Services\FollowManager;
use App\Federation\Actors\Actor;
use Illuminate\Contracts\View\View;

/**
 * Vista condivisa dell'elenco follower/seguiti: la stessa pagina serve sia
 * un profilo locale ("/@utente/follower") sia un Actor remoto in cache
 * ("/attori/{id}/follower"), dato che {@see Actor} unifica gia' i due casi.
 */
trait RendersFollowLists
{
    private function renderFollowList(FollowListQuery $followListQuery, FollowManager $followManager, Actor $owner, string $type): View
    {
        $paginator = $type === 'followers'
            ? $followListQuery->followers($owner)
            : $followListQuery->following($owner);

        $viewerActor = auth()->user()?->actor;
        $statusMap = $viewerActor !== null
            ? $followManager->statusMapFor($viewerActor, $paginator->getCollection())
            : [];

        return view('follows.index', [
            'owner' => $owner,
            'type' => $type,
            'actors' => $paginator,
            'viewerActor' => $viewerActor,
            'statusMap' => $statusMap,
        ]);
    }
}
