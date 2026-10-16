<?php

namespace App\Console\Commands;

use App\Application\Queries\PollWorkQuery;
use App\Application\Services\PollResultsProcessor;
use App\Domain\Polls\Poll;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessPollsCommand extends Command
{
    protected $signature = 'openbook:process-polls
        {--max-time=5 : Tempo massimo in secondi}
        {--limit=100 : Numero massimo di chiusure e batch di distribuzione}
        {--batch-size=50 : Destinazioni per sondaggio e passaggio}';

    protected $description = 'Chiude i sondaggi scaduti e accoda pubblicazione e risultati pendenti.';

    public function handle(PollResultsProcessor $processor, PollWorkQuery $work): int
    {
        $deadline = microtime(true) + max(1, (int) $this->option('max-time'));
        $limit = min(1000, max(1, (int) $this->option('limit')));
        $batch = min(200, max(1, (int) $this->option('batch-size')));
        $closed = $queued = 0;
        $status = self::SUCCESS;
        $expired = $work->expired()->limit($limit)->pluck('id');
        foreach ($expired as $id) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $closed += (int) $processor->close($id);
        }
        $pending = $work->pending()->limit($limit)->pluck('id');
        // Round-robin batches keep large audiences resumable without monopolizing a pass.
        $pending = $expired->intersect($pending)->concat($pending)->unique()->values();
        $remaining = $limit;
        while ($pending->isNotEmpty() && $remaining > 0 && microtime(true) < $deadline) {
            $next = collect();
            foreach ($pending as $id) {
                if ($remaining < 1 || microtime(true) >= $deadline) {
                    break;
                }
                $remaining--;
                try {
                    $queued += $processor->distribute($id, $batch);
                    if (Poll::query()->whereKey($id)->where('needs_delivery', true)->exists()) {
                        $next->push($id);
                    }
                } catch (Throwable $exception) {
                    Log::channel('single')->error('polls.distribution_failed', ['poll_id' => $id, 'error' => $exception->getMessage()]);
                    $status = self::FAILURE;
                }
            }
            $pending = $next;
        }
        $this->info("Sondaggi chiusi: {$closed}; consegne accodate: {$queued}.");

        return $status;
    }
}
