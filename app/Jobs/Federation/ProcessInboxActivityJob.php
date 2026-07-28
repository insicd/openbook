<?php

namespace App\Jobs\Federation;

use App\Federation\Inbox\InboxActivityProcessor;
use App\Federation\Inbox\InboxItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Elabora un'attivita' gia' ricevuta e verificata (una riga "inbox_items" in
 * stato "pending"): pochi tentativi ravvicinati, perche' un fallimento qui e'
 * quasi sempre dovuto a un problema transitorio locale (contesa sul
 * database) piuttosto che alla rete remota, gia' superata al momento della
 * ricezione.
 */
final class ProcessInboxActivityJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 30, 120, 600];

    public int $tries = 5;

    public function __construct(
        public readonly string $inboxItemId,
    ) {
        $this->onQueue('inbox');
    }

    public function handle(InboxActivityProcessor $processor): void
    {
        $item = InboxItem::query()->find($this->inboxItemId);

        if ($item === null || $item->status !== InboxItem::STATUS_PENDING) {
            return;
        }

        try {
            $status = $processor->process($item);
        } catch (Throwable $exception) {
            $item->update([
                'status' => InboxItem::STATUS_FAILED,
                'error' => mb_substr($exception->getMessage(), 0, 500),
            ]);

            throw $exception;
        }

        $item->update([
            'status' => $status,
            'processed_at' => now(),
            'error' => null,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel('single')->warning('federation.inbox_processing_failed', [
            'inbox_item_id' => $this->inboxItemId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
