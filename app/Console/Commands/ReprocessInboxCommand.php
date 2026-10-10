<?php

namespace App\Console\Commands;

use App\Federation\Inbox\InboxItem;
use App\Jobs\Federation\ProcessInboxActivityJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Accoda le attività ignorate e quelle pendenti, anche se importate senza job. */
class ReprocessInboxCommand extends Command
{
    protected $signature = 'openbook:reprocess-inbox
        {--chunk=500 : Numero massimo di righe rimesse in coda per blocco}';

    protected $description = 'Accoda le attività federate ignorate o pendenti ancora presenti nel database.';

    public function handle(): int
    {
        $chunkSize = max(1, min(5000, (int) $this->option('chunk')));
        $count = 0;
        $statuses = [InboxItem::STATUS_IGNORED, InboxItem::STATUS_PENDING];

        InboxItem::query()
            ->select('id')
            ->whereIn('status', $statuses)
            ->orderBy('id')
            ->chunkById($chunkSize, function ($items) use (&$count, $statuses): void {
                $ids = $items->pluck('id')->all();

                $requeued = DB::transaction(function () use ($ids, $statuses): array {
                    $requeued = InboxItem::query()
                        ->whereIn('id', $ids)
                        ->whereIn('status', $statuses)
                        ->pluck('id')
                        ->all();

                    if ($requeued !== []) {
                        InboxItem::query()
                            ->whereIn('id', $requeued)
                            ->whereIn('status', $statuses)
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
            '%d attività ignorate o pendenti accodate nella coda inbox.',
            $count,
        ));

        return self::SUCCESS;
    }
}
