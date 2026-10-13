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
 * e Create personale del Group verso i follower remoti che l'hanno chiesta.
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
            $this->deliverToRemoteNotifyFollowers($group, $post);

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

    private function deliverToRemoteNotifyFollowers(Actor $group, Post $post): void
    {
        $followers = Actor::query()
            ->where('is_local', false)
            ->where('status', Actor::STATUS_ACTIVE)
            ->whereKeyNot($post->actor_id)
            ->whereIn('id', Follow::query()
                ->select('follower_id')
                ->where('following_id', $group->id)
                ->where('status', Follow::STATUS_ACCEPTED)
                ->where('notify', true))
            ->with('endpoints')
            ->get();

        foreach ($followers as $follower) {
            $this->delivery->deliverTo(
                $group,
                $follower,
                GroupNotifyNoteSerializer::create($group, $post, $follower),
            );
        }
    }
}
