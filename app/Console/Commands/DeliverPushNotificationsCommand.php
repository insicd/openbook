<?php

namespace App\Console\Commands;

use App\Application\Services\InstanceSettings;
use App\Domain\Notifications\Notification;
use App\Domain\Notifications\PushNotification;
use App\Domain\Notifications\PushSubscription;
use App\Infrastructure\Push\BrowserPushGateway;
use App\Infrastructure\Push\PushDeliveryStatus;
use Illuminate\Console\Command;

/**
 * Consuma l'outbox delle notifiche Web Push senza introdurre un worker
 * permanente. Le righe piu' recenti del periodo di grazia restano in attesa,
 * cosi' il poller del browser puo' eliminarle quando l'utente e' gia' attivo
 * sul sito. Ogni riga pronta viene acquisita tramite una cancellazione
 * condizionale prima dell'invio: cron concorrenti non possono quindi
 * consegnare due volte la stessa notifica.
 *
 * La consegna e' volutamente best effort. Dopo l'acquisizione non sono
 * previsti retry; la notifica locale rimane comunque disponibile su Openbook.
 */
class DeliverPushNotificationsCommand extends Command
{
    protected $signature = 'openbook:deliver-push
        {--max-time=15 : Tempo massimo di esecuzione in secondi}';

    protected $description = 'Consegna le notifiche Web Push oltre il periodo di grazia e consuma la relativa outbox.';

    public function handle(BrowserPushGateway $gateway, InstanceSettings $instanceSettings): int
    {
        $deadline = microtime(true) + max(1, (int) $this->option('max-time'));
        $gracePeriod = max(0, (int) config('openbook.push.grace_period_seconds', 75));
        $threshold = now()->subSeconds($gracePeriod);
        $batchSize = max(1, (int) config('openbook.push.batch_size', 20));

        $candidates = PushNotification::query()
            ->where('created_at', '<=', $threshold)
            ->orderBy('created_at')
            ->limit($batchSize)
            ->get(['id', 'notification_id']);

        $claimed = 0;
        $attempted = 0;
        $delivered = 0;
        $invalidSubscriptions = 0;

        foreach ($candidates as $candidate) {
            if (microtime(true) >= $deadline) {
                break;
            }

            $wonClaim = PushNotification::query()
                ->whereKey($candidate->id)
                ->where('created_at', '<=', $threshold)
                ->delete();

            if ($wonClaim !== 1) {
                continue;
            }

            $claimed++;
            $notification = Notification::query()
                ->with(['actor.user.profile', 'recipient.settings', 'notifiable'])
                ->find($candidate->notification_id);

            if ($notification === null || $notification->isRead() || $notification->recipient === null) {
                continue;
            }

            $subscriptions = PushSubscription::query()
                ->where('user_id', $notification->recipient_id)
                ->get();

            if ($subscriptions->isEmpty()) {
                continue;
            }

            $payload = $this->payload($notification, $instanceSettings);
            $nowMilliseconds = (int) floor(microtime(true) * 1000);

            foreach ($subscriptions as $subscription) {
                if ($subscription->expiration_time !== null
                    && $subscription->expiration_time <= $nowMilliseconds) {
                    $subscription->delete();
                    $invalidSubscriptions++;

                    continue;
                }

                $attempted++;
                $status = $gateway->send($subscription, $payload);

                if ($status === PushDeliveryStatus::Delivered) {
                    $delivered++;
                } elseif ($status === PushDeliveryStatus::InvalidSubscription) {
                    $subscription->delete();
                    $invalidSubscriptions++;
                }
            }
        }

        $this->info(
            "Push acquisite: {$claimed}; tentativi: {$attempted}; consegnate: {$delivered}; sottoscrizioni eliminate: {$invalidSubscriptions}.",
        );

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function payload(Notification $notification, InstanceSettings $instanceSettings): array
    {
        $locale = $notification->recipient?->settings?->locale;
        $payload = [
            'title' => $instanceSettings->siteName(),
            'body' => $notification->message($locale),
            'url' => route('notifications.index'),
            'tag' => 'notification-'.$notification->id,
        ];

        $icon = $instanceSettings->androidIconUrl(192);
        if ($icon !== null) {
            $payload['icon'] = $icon;
        }

        return $payload;
    }
}
