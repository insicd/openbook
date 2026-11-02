<?php

namespace App\Http\Controllers\Rest\V1;

use App\Application\Queries\PersonQuery;
use App\Application\Services\CommunityMembershipService;
use App\Application\Services\FollowManager;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Http\Controllers\Concerns\RespondsWithConditionalRest;
use App\Http\Controllers\Controller;
use App\Http\Resources\Rest\V1\FollowResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final class PersonFollowController extends Controller
{
    use RespondsWithConditionalRest;

    public function show(Request $request, string $personId, PersonQuery $persons, FollowManager $follows): Response
    {
        $target = $this->target($personId, $persons);
        $status = $this->status($follows, $request->user()->actor, $target);

        return $this->conditionalResponse($request, (new FollowResource($status))->toResponse($request));
    }

    public function store(Request $request, string $personId, PersonQuery $persons, FollowManager $follows, CommunityMembershipService $memberships): Response
    {
        try {
            $follow = DB::transaction(function () use ($request, $personId, $persons, $follows, $memberships): Follow {
                $target = $this->target($personId, $persons, lock: true);
                $viewer = $request->user()->actor;
                if ($target->isGroup() && $target->community !== null) {
                    Gate::authorize('join', $target->community);

                    return $memberships->join($viewer, $target->community);
                }

                return $follows->follow($viewer, $target);
            });
        } catch (InvalidArgumentException) {
            abort(422);
        }
        $response = (new FollowResource($follow->status))->toResponse($request);
        $response->setStatusCode($follow->wasRecentlyCreated ? 201 : 200);
        if ($follow->wasRecentlyCreated) {
            $response->headers->set('Location', $request->url());
        }
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    public function destroy(Request $request, string $personId, PersonQuery $persons, FollowManager $follows, CommunityMembershipService $memberships): Response
    {
        try {
            DB::transaction(function () use ($request, $personId, $persons, $follows, $memberships): void {
                $target = $this->target($personId, $persons, lock: true);
                $viewer = $request->user()->actor;
                if ($target->isGroup() && $target->community !== null) {
                    if ($this->status($follows, $viewer, $target) !== 'none') {
                        Gate::authorize('leave', $target->community);
                        $memberships->leave($viewer, $target->community);
                    }

                    return;
                }
                $follows->unfollow($viewer, $target);
            });
        } catch (InvalidArgumentException) {
            abort(422);
        }

        return response()->noContent()->header('Cache-Control', 'no-store');
    }

    private function target(string $personId, PersonQuery $persons, bool $lock = false): Actor
    {
        abort_unless(Str::isUuid($personId), 404);
        $query = $persons->available()->with(['user', 'community']);
        if ($lock) {
            $query->lockForUpdate();
        }
        $target = $query->find($personId);
        abort_if($target === null, 404);

        return $target;
    }

    private function status(FollowManager $follows, Actor $viewer, Actor $target): string
    {
        $state = $follows->statusMapFor($viewer, [$target])[$target->id] ?? [];

        return ($state['following'] ?? false) ? Follow::STATUS_ACCEPTED
            : (($state['pending'] ?? false) ? Follow::STATUS_PENDING : 'none');
    }
}
