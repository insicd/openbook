<?php

namespace App\Http\Support;

use App\Application\Services\FollowManager;
use App\Federation\Actors\Actor;
use Illuminate\Http\JsonResponse;

/** Riusa la riga della directory per mantenere identici gli stati dopo un'azione. */
final class CommunityDirectoryRowResponse
{
    public function __construct(private readonly FollowManager $follows) {}

    public function forActor(Actor $actor, Actor $viewer): JsonResponse
    {
        $actor = $actor->fresh(['community']) ?? abort(404);
        $statusMap = $this->follows->statusMapFor($viewer, [$actor]);
        $following = $statusMap[$actor->id]['following'] ?? false;
        $pending = $statusMap[$actor->id]['pending'] ?? false;
        $community = $actor->community;

        return response()->json([
            'html' => view('communities._directory_item', [
                'actor' => $actor,
                'statusMap' => $statusMap,
            ])->render(),
            'following' => $following,
            'listed' => [
                'mine' => $following,
                'local' => $community === null || ! $community->is_private
                    || $community->owner_user_id === $viewer->user_id
                    || $viewer->user?->isStaff()
                    || $following,
                'remote' => $actor->discoverable || $following || $pending,
            ],
        ]);
    }
}
