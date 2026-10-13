<?php

namespace App\Application\Services;

use App\Domain\Notifications\Notification;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Delivery\ActivityDelivery;
use App\Federation\Serialization\GroupNotifyNoteSerializer;

/**
 * Campanella sui Group: in-app per i follower locali con {@see Follow::$notify},
 * e Create originale del Group verso tutti i membri remoti (Mastodon non
 * federata il flag {@code notify}: lo applica in locale sull'home feed).
 */
final class GroupFollowerNotifier
{
    public function __construct(
        private readonly NotificationCreator $notificationCreator,
        private readonly ActivityDelivery $delivery,
    ) {}

    public function afterGroupAnnounce(Actor $group, Post $post): void
    {
        if (! $group->isGroup() || ! $group->isActive()) {
            return;
        }

        $post->loadMissing('actor');

        if ($group->isLocal()) {
            $this->deliverToRemoteMembers($group, $post);

            return;
        }

        $this->notifyLocalNotifyFollowers($group, $post);
    }

    private function notifyLocalNotifyFollowers(Actor $group, Post $post): void
    {
        $authorId = $post->actor_id;
        $members = Actor::query()
            ->where('is_local', true)
            ->where('type', Actor::TYPE_PERSON)
            ->where('status', Actor::STATUS_ACTIVE)
            ->whereKeyNot($authorId)
            ->whereIn('id', Follow::query()
                ->select('follower_id')
                ->where('following_id', $group->id)
                ->where('status', Follow::STATUS_ACCEPTED)
                ->where('notify', true))
            ->get();

        foreach ($members as $member) {
            if ($member->user_id !== null && Notification::query()
                ->where('recipient_id', $member->user_id)
                ->where('type', Notification::TYPE_COMMUNITY_POST)
                ->where('notifiable_id', $post->id)
                ->exists()) {
                continue;
            }

            $this->notificationCreator->notify(
                $member,
                Notification::TYPE_COMMUNITY_POST,
                $post->actor,
                $post,
            );
        }
    }

    private function deliverToRemoteMembers(Actor $group, Post $post): void
    {
        $this->delivery->deliverToFollowers(
            $group,
            GroupNotifyNoteSerializer::create($group, $post),
        );
    }
}
