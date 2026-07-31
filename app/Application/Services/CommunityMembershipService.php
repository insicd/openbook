<?php

namespace App\Application\Services;

use App\Domain\Communities\Community;
use App\Domain\Notifications\Notification;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Iscrizione / uscita da una community: riusa Follow verso l'Actor Group e
 * mantiene members_count + notifica al proprietario.
 */
final class CommunityMembershipService
{
    public function __construct(
        private readonly FollowManager $followManager,
        private readonly NotificationCreator $notificationCreator,
    ) {}

    public function join(Actor $member, Community $community): Follow
    {
        $community->loadMissing('actor', 'owner.actor');

        $follow = $this->followManager->follow($member, $community->actor);

        if ($follow->wasRecentlyCreated) {
            if ($follow->status === Follow::STATUS_ACCEPTED) {
                $community->increment('members_count');
            }

            if ($community->owner?->actor !== null) {
                $this->notificationCreator->notify(
                    $community->owner->actor,
                    $follow->status === Follow::STATUS_PENDING
                        ? Notification::TYPE_FOLLOW_REQUEST
                        : Notification::TYPE_NEW_FOLLOWER,
                    $member,
                    $follow,
                );
            }
        }

        return $follow;
    }

    public function leave(Actor $member, Community $community): void
    {
        if ($community->owner_user_id === $member->user_id) {
            throw new InvalidArgumentException(__('openbook.communities.errors.owner_cannot_leave'));
        }

        $existing = Follow::query()
            ->where('follower_id', $member->id)
            ->where('following_id', $community->actor_id)
            ->first();

        if ($existing === null) {
            return;
        }

        $wasAccepted = $existing->status === Follow::STATUS_ACCEPTED;

        $this->followManager->unfollow($member, $community->actor);

        if ($wasAccepted) {
            Community::query()
                ->whereKey($community->id)
                ->where('members_count', '>', 0)
                ->decrement('members_count');
        }
    }

    public function accept(Community $community, Follow $follow): void
    {
        abort_unless($follow->following_id === $community->actor_id, 404);
        abort_unless($follow->status === Follow::STATUS_PENDING, 404);

        DB::transaction(function () use ($community, $follow): void {
            $this->followManager->accept($community->actor, $follow->follower);
            $community->increment('members_count');
        });
    }

    public function reject(Community $community, Follow $follow): void
    {
        abort_unless($follow->following_id === $community->actor_id, 404);

        $this->followManager->reject($community->actor, $follow->follower);
    }
}
