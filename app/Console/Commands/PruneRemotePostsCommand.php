<?php

namespace App\Console\Commands;

use App\Application\Queries\RemotePostRetentionQuery;
use App\Application\Services\InstanceSettings;
use Illuminate\Console\Command;

final class PruneRemotePostsCommand extends Command
{
    protected $signature = 'openbook:prune-remote-posts
        {--dry-run : Mostra i candidati senza modificare il database}
        {--batch-size=100 : Numero massimo di post selezionati per fascia}
        {--sample=10 : Numero massimo di link mostrati per fascia (0 per nessuno)}';

    protected $description = 'Mostra un’anteprima della retention dei post remoti pertinenti e non pertinenti.';

    public function handle(RemotePostRetentionQuery $retention, InstanceSettings $settings): int
    {
        if (! $this->option('dry-run')) {
            $this->error('Per ora è disponibile solo l’anteprima: aggiungere --dry-run.');

            return self::INVALID;
        }

        $batchSize = filter_var($this->option('batch-size'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $sample = filter_var($this->option('sample'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if ($batchSize === false || $sample === false) {
            $this->error('--batch-size deve essere un intero positivo; --sample un intero maggiore o uguale a zero.');

            return self::INVALID;
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
}
