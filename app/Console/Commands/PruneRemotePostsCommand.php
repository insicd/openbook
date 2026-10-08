<?php

namespace App\Console\Commands;

use App\Application\Queries\RemotePostRetentionQuery;
use App\Application\Services\InstanceSettings;
use App\Application\Services\RemotePostRetention;
use Illuminate\Console\Command;

final class PruneRemotePostsCommand extends Command
{
    protected $signature = 'openbook:prune-remote-posts
        {--dry-run : Mostra i candidati senza modificare il database}
        {--batch-size=100 : Numero massimo di post selezionati per fascia}
        {--sample=10 : Numero massimo di link mostrati per fascia in dry-run (0 per nessuno)}
        {--max-time=1800 : Tempo massimo in secondi; il batch in corso viene completato}';

    protected $description = 'Elimina i post remoti scaduti secondo le due policy di retention; --dry-run mostra l’anteprima.';

    public function handle(RemotePostRetentionQuery $retention, InstanceSettings $settings, RemotePostRetention $pruner): int
    {
        $batchSize = filter_var($this->option('batch-size'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $sample = filter_var($this->option('sample'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        $maxTime = filter_var($this->option('max-time'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($batchSize === false || $sample === false || $maxTime === false) {
            $this->error('--batch-size deve essere un intero positivo; --sample un intero maggiore o uguale a zero; --max-time un intero positivo.');

            return self::INVALID;
        }

        if (! $this->option('dry-run')) {
            return $this->prune($pruner, $batchSize, $maxTime);
        }

        $this->info('Anteprima retention (dry-run): nessun dato modificato.');
        $this->line("Un solo batch per fascia, massimo {$batchSize} post. I conteggi non sono totali globali.");
        $asOf = now();

        foreach ([
            'Non pertinenti' => ['nonPertinent', $settings->remotePostNonPertinentRetentionDays()],
            'Pertinenti' => ['pertinent', $settings->remotePostPertinentRetentionDays()],
        ] as $label => [$method, $days]) {
            if ($days === 0) {
                $this->line("{$label}: disabilitata (0 giorni).");

                continue;
            }

            $posts = $retention->$method($batchSize, $asOf)->get();
            $this->line("{$label}: {$days} giorni; candidati nel batch: {$posts->count()}.");

            foreach ($posts->take($sample) as $post) {
                $this->line(route('posts.show', ['post' => $post->id]));
            }
        }

        return self::SUCCESS;
    }

    private function prune(RemotePostRetention $pruner, int $batchSize, int $maxTime): int
    {
        // A process-held file lock has no expiry while a large cascade finishes.
        $lock = fopen(storage_path('framework/cache/remote-post-retention.lock'), 'c');
        if ($lock === false) {
            $this->error('Impossibile aprire il lock della retention.');

            return self::FAILURE;
        }

        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                $this->warn('Retention già in esecuzione: nessuna cancellazione avviata.');

                return self::SUCCESS;
            }

            $result = $pruner->prune($batchSize, $maxTime);
            $this->info("Post eliminati: Non pertinenti {$result['nonPertinent']}; Pertinenti {$result['pertinent']}.");
            if ($result['timed_out']) {
                $this->comment('Limite di tempo raggiunto: eseguire nuovamente per continuare.');
            }

            return self::SUCCESS;
        } finally {
            fclose($lock);
        }
    }
}
