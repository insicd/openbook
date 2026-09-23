<?php

namespace App\Console\Commands;

use App\Application\Services\AutoAnnounceFanout;
use Illuminate\Console\Command;

/**
 * Esegue le condivisioni dirette automatiche dei contatti con l'opzione
 * attiva. Richiamato da {@see CronCommand} dopo inbox e fetch dei feed,
 * cosi' i post appena arrivati possono essere condivisi nello stesso ciclo.
 */
class AutoAnnounceCommand extends Command
{
    protected $signature = 'openbook:auto-announce
        {--limit=20 : Numero massimo di follow da esaminare}
        {--per-follow=8 : Post massimi da condividere per ciascun follow}
        {--max-time=8 : Tempo massimo di esecuzione in secondi}';

    protected $description = 'Condivide automaticamente i nuovi post pubblici dei contatti con condivisione diretta attiva.';

    public function handle(AutoAnnounceFanout $fanout): int
    {
        $created = $fanout->process(
            max(1, (int) $this->option('limit')),
            max(1, (int) $this->option('per-follow')),
            microtime(true) + max(1, (int) $this->option('max-time')),
        );

        $this->info("Condivisioni automatiche create: {$created}.");

        return self::SUCCESS;
    }
}
