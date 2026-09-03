<?php

namespace App\Application\Services;

use App\Domain\Accounts\User;
use App\Domain\Comments\Comment;
use App\Domain\Notifications\Notification;
use App\Domain\Notifications\PushNotification;
use App\Domain\Notifications\PushSubscription;
use App\Federation\Actors\Actor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Centralizza la creazione delle notifiche locali, cosi' che ogni servizio
 * applicativo (follow, like, commenti, menzioni, condivisioni) non debba
 * duplicare la logica "non notificare mai un utente di una propria azione".
 *
 * Su un commento, menzione e commento/risposta puntano allo stesso
 * {@see Notification::$notifiable_id}: Mastodon & co. mandano entrambi
 * (inReplyTo + tag Mention). Se il destinatario ha gia' la notifica di
 * thread, la menzione non viene creata.
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

        if ($type === Notification::TYPE_MENTION && $this->alreadyNotifiedForCommentThread($recipientActor->user_id, $notifiable)) {
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

        $notificationId = $notification->id;
        $recipientId = $recipientActor->user_id;

        DB::afterCommit(static function () use ($notificationId, $recipientId): void {
            try {
                if (! PushSubscription::query()->where('user_id', $recipientId)->exists()) {
                    return;
                }

                PushNotification::query()->firstOrCreate(['notification_id' => $notificationId]);
            } catch (Throwable $exception) {
                // Il canale push e' accessorio: la notifica locale resta valida.
                Log::warning('Unable to enqueue a browser push notification.', [
                    'notification_id' => $notificationId,
                    'exception' => $exception,
                ]);
            }
        });

        return $notification;
    }

    /**
     * Commento/risposta e menzione sullo stesso commento sono lo stesso
     * evento per chi ha scritto il post (o il commento padre). Si usa
     * l'id del commento e il destinatario del thread, non una finestra
     * oraria: due persone diverse nello stesso secondo restano distinte.
     */
    private function alreadyNotifiedForCommentThread(string $recipientId, Model $notifiable): bool
    {
        if (! $notifiable instanceof Comment) {
            return false;
        }

        $notifiable->loadMissing(['parent.actor', 'post.actor']);

        $threadTarget = $notifiable->parent !== null
            ? $notifiable->parent->actor
            : $notifiable->post?->actor;

        if ($threadTarget !== null && $threadTarget->user_id === $recipientId) {
            return true;
        }

        return Notification::query()
            ->where('recipient_id', $recipientId)
            ->where('notifiable_type', $notifiable->getMorphClass())
            ->where('notifiable_id', $notifiable->getKey())
            ->whereIn('type', [Notification::TYPE_COMMENT, Notification::TYPE_REPLY])
            ->exists();
    }
}
