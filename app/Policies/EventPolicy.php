<?php

namespace App\Policies;

use App\Domain\Accounts\User;
use App\Domain\Events\Event;

class EventPolicy
{
    public function update(User $user, Event $event): bool
    {
        return ! $event->isRemote()
            && ! $event->isDeleted()
            && $event->actor?->user_id === $user->id;
    }

    public function delete(User $user, Event $event): bool
    {
        return ! $event->isRemote()
            && ! $event->isDeleted()
            && $event->actor?->user_id === $user->id;
    }
}
