<?php

namespace App\Console\Commands;

use App\Federation\Inbox\InboxItem;
use App\Jobs\Federation\ProcessInboxActivityJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Rimette nella normale coda inbox tutte le attività precedentemente ignorate. */
class ReprocessInboxCommand extends Command
{
    protected $signature = 'openbook:reprocess-inbox
        {--chunk=500 : Numero massimo di righe rimesse in coda per blocco}';

    protected $description = 'Rimette in coda le attività federate ignorate ancora presenti nel database.';

    public function handle(): int
    {
        $chunkSize = max(1, min(5000, (int) $this->option('chunk')));
        $count = 0;

        InboxItem::query()
            ->where('status', InboxItem::STATUS_IGNORED)
            ->orderBy('id')
            ->chunkById($chunkSize, function ($items) use (&$count): void {
                $ids = $items->pluck('id')->all();

                $requeued = DB::transaction(function () use ($ids): array {
                    $requeued = InboxItem::query()
                        ->whereIn('id', $ids)
                        ->where('status', InboxItem::STATUS_IGNORED)
                        ->pluck('id')
                        ->all();

                    if ($requeued !== []) {
                        InboxItem::query()
                            ->whereIn('id', $requeued)
                            ->update([
                                'status' => InboxItem::STATUS_PENDING,
                                'processed_at' => null,
                                'error' => null,
                                'updated_at' => now(),
                            ]);
                    }

                    return $requeued;
                });

                foreach ($requeued as $id) {
                    ProcessInboxActivityJob::dispatch($id);
                }

                $count += count($requeued);
            });

        $this->info(sprintf(
            '%d attività ignorate rimesse nella coda inbox.',
            $count,
        ));

        return self::SUCCESS;
    }
}
