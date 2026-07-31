<?php

namespace App\Application\Services;

use App\Domain\Accounts\User;
use App\Domain\Notifications\Notification;
use App\Federation\Actors\Actor;
use Illuminate\Database\Eloquent\Model;

/**
 * Centralizza la creazione delle notifiche locali, cosi' che ogni servizio
 * applicativo (follow, like, commenti, menzioni, condivisioni) non debba
 * duplicare la logica "non notificare mai un utente di una propria azione".
 */
final class NotificationCreator
{
    public function notify(Actor $recipientActor, string $type, ?Actor $causedBy, Model $notifiable): ?Notification
    {
        if (! $recipientActor->isLocal() || $recipientActor->user_id === null) {
            return null;
        }

        if ($causedBy !== null && $causedBy->id === $recipientActor->id) {
            return null;
        }

        $notification = Notification::query()->create([
            'recipient_id' => $recipientActor->user_id,
            'actor_id' => $causedBy?->id,
            'type' => $type,
            'notifiable_type' => $notifiable->getMorphClass(),
            'notifiable_id' => $notifiable->getKey(),
        ]);

        User::query()->whereKey($recipientActor->user_id)->increment('notifications_revision');

        return $notification;
    }
}
