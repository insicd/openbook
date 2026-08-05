<?php

namespace App\Jobs\Federation;

use App\Federation\Actors\Actor;
use App\Federation\Delivery\ActivityDelivery;
use App\Infrastructure\Security\Http\SafeHttpClient;
use App\Infrastructure\Security\Http\SsrfViolationException;
use App\Infrastructure\Security\HttpSignatureSigner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Consegna una singola attivita' ActivityStreams a una singola inbox remota,
 * firmata con la chiave dell'Actor locale che l'ha originata. Un job per
 * ogni inbox di destinazione: {@see ActivityDelivery}
 * si occupa gia' di calcolare l'insieme di inbox uniche (deduplicate sulla
 * "sharedInbox" quando piu' follower vivono sullo stesso server remoto).
 *
 * Il numero di tentativi e gli intervalli di backoff vengono letti da
 * "config('openbook.delivery')" cosi' da restare configurabili senza
 * toccare il codice. Un fallimento definitivo (tutti i tentativi esauriti)
 * finisce nella tabella "failed_jobs" standard di Laravel: non blocca ne'
 * rallenta il resto della coda.
 */
final class DeliverActivityJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var list<int>
     */
    public array $backoff;

    public int $tries;

    /**
     * @param  array<string, mixed>  $activity
     */
    public function __construct(
        public readonly string $inboxUrl,
        public readonly array $activity,
        public readonly string $signingActorId,
    ) {
        $this->onQueue('delivery');

        $this->tries = max(1, (int) config('openbook.delivery.max_attempts', 10));

        $this->backoff = array_map(
            static fn (int $minutes): int => $minutes * 60,
            config('openbook.delivery.retry_intervals_minutes', [1, 5, 15, 60, 360, 1440])
        );
    }

    public function handle(SafeHttpClient $client): void
    {
        $actor = Actor::query()->with('key')->find($this->signingActorId);

        if ($actor === null || $actor->key === null || ! $actor->key->hasPrivateKey()) {
            // Non e' un errore transitorio (nessun retry ha senso: la chiave
            // non comparira' magicamente al prossimo tentativo).
            $this->fail(new RuntimeException(
                "Impossibile firmare la consegna: chiave privata assente per l'Actor {$this->signingActorId}."
            ));

            return;
        }

        $body = json_encode($this->activity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $headers = (new HttpSignatureSigner)->authorizationHeaders(
            'POST',
            $this->inboxUrl,
            $actor,
            $body,
        );

        try {
            $response = $client->post($this->inboxUrl, $body, $headers);
        } catch (SsrfViolationException $exception) {
            // Un'inbox che non risolve piu' a un indirizzo pubblico non e'
            // un problema temporaneo di rete: non ha senso ritentare.
            $this->fail($exception);

            return;
        }

        if (! $response->successful()) {
            Log::channel('single')->info('federation.delivery_rejected', [
                'inbox' => $this->inboxUrl,
                'activity_type' => $this->activity['type'] ?? null,
                'activity_id' => $this->activity['id'] ?? null,
                'http_status' => $response->status,
                'body' => mb_substr($response->body, 0, 500),
            ]);

            throw new RuntimeException(
                "Consegna a {$this->inboxUrl} fallita con HTTP {$response->status}."
            );
        }

        Log::channel('single')->info('federation.delivery_ok', [
            'inbox' => $this->inboxUrl,
            'activity_type' => $this->activity['type'] ?? null,
            'activity_id' => $this->activity['id'] ?? null,
            'http_status' => $response->status,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel('single')->warning('federation.delivery_failed', [
            'inbox' => $this->inboxUrl,
            'activity_type' => $this->activity['type'] ?? null,
            'activity_id' => $this->activity['id'] ?? null,
            'error' => $exception?->getMessage(),
        ]);
    }
}
