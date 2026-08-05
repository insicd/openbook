<?php

namespace App\Jobs\Federation;

use App\Domain\SocialGraph\Follow;
use App\Federation\SocialGraph\OutgoingFollowConfirmer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Dopo una consegna Follow 2xx, verifica se il remoto ci ha gia' messi
 * nella collection followers (Accept mancante o perso).
 */
final class ConfirmOutgoingFollowJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /**
     * @var list<int>
     */
    public array $backoff = [20, 45, 90, 180, 300];

    public function __construct(
        public readonly string $followId,
    ) {
        $this->onQueue('delivery');
    }

    public function handle(OutgoingFollowConfirmer $confirmer): void
    {
        $follow = Follow::query()->find($this->followId);

        if ($follow === null || $follow->status !== Follow::STATUS_PENDING) {
            return;
        }

        if ($confirmer->confirm($follow)) {
            return;
        }

        // Non ancora in collection: ritenta con backoff finche' ci sono tentativi.
        if ($this->attempts() < $this->tries) {
            throw new \RuntimeException(
                "Follow {$this->followId} non ancora presente nella collection followers remota."
            );
        }
    }
}
