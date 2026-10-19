<?php

namespace App\Application\Services;

use App\Domain\Accounts\User;
use App\Domain\Notifications\Notification;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;

final class AccountMoveNotifier
{
    public function __construct(private readonly NotificationCreator $notifications) {}

    public function notifyFollowers(Actor $source, Actor $target, bool $deduplicate = true): void
    {
        // The profile may already have supplied movedTo without any Move.
        // Deduplicate notifications themselves, not the informational link.
        $notified = $deduplicate ? array_flip(Notification::query()
            ->where('actor_id', $source->id)
            ->where('type', Notification::TYPE_ACCOUNT_MOVED)
            ->where('notifiable_type', $target->getMorphClass())
            ->where('notifiable_id', $target->id)
            ->pluck('recipient_id')->all()) : [];

        $followers = Actor::query()
            ->select('actors.*')
            ->join('follows', 'follows.follower_id', '=', 'actors.id')
            ->join('users', 'users.id', '=', 'actors.user_id')
            ->where('follows.following_id', $source->id)
            ->where('follows.status', Follow::STATUS_ACCEPTED)
            ->where('actors.is_local', true)
            ->where('actors.type', Actor::TYPE_PERSON)
            ->where('actors.status', Actor::STATUS_ACTIVE)
            ->where('users.status', User::STATUS_ACTIVE)
            ->get();

        foreach ($followers as $follower) {
            if (isset($notified[$follower->user_id])) {
                continue;
            }
            // The destination is the stable notification target. Following
            // it remains an explicit user action, preserving old follows.
            $this->notifications->notify($follower, Notification::TYPE_ACCOUNT_MOVED, $source, $target);
        }
    }
}
