<?php

namespace App\Application\Services;

use App\Domain\Communities\Community;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use InvalidArgumentException;

/**
 * Iscrizione / uscita da una community locale: riusa Follow verso l'Actor
 * Group (contatori e notifica al proprietario sono in {@see FollowManager}).
 */
final class CommunityMembershipService
{
    public function __construct(
        private readonly FollowManager $followManager,
    ) {}

    public function join(Actor $member, Community $community): Follow
    {
        $community->loadMissing('actor');

        return $this->followManager->follow($member, $community->actor);
    }

    public function leave(Actor $member, Community $community): void
    {
        if ($community->owner_user_id === $member->user_id) {
            throw new InvalidArgumentException(__('openbook.communities.errors.owner_cannot_leave'));
        }

        $this->followManager->unfollow($member, $community->actor);
    }

    public function accept(Community $community, Follow $follow): void
    {
        abort_unless($follow->following_id === $community->actor_id, 404);
        abort_unless($follow->status === Follow::STATUS_PENDING, 404);

        $this->followManager->accept($community->actor, $follow->follower);
    }

    public function reject(Community $community, Follow $follow): void
    {
        abort_unless($follow->following_id === $community->actor_id, 404);

        $this->followManager->reject($community->actor, $follow->follower);
    }
}
