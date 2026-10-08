<?php

namespace App\Console\Commands;

use App\Application\Queries\DatabaseSanityQuery;
use App\Application\Services\DatabaseSanity;
use Illuminate\Console\Command;

final class DatabaseSanityCommand extends Command
{
    protected $signature = 'openbook:database-sanity
        {--scheduled : Esegue al massimo una volta ogni 24 ore, per il cron ordinario}
        {--dry-run : Mostra gli orfani senza modificare il database}
        {--batch-size=100 : Numero massimo di righe selezionate per tabella/tipo in ogni batch}
        {--max-time=1800 : Tempo massimo in secondi; il batch in corso viene completato}';

    protected $description = 'Riconcilia like, menzioni e notifiche orfani, indipendentemente dalla retention.';

    public function handle(DatabaseSanityQuery $query, DatabaseSanity $sanity): int
    {
        $batchSize = filter_var($this->option('batch-size'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $maxTime = filter_var($this->option('max-time'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($batchSize === false || $maxTime === false) {
            $this->error('--batch-size e --max-time devono essere interi positivi.');

            return self::INVALID;
        }

        if ($this->option('dry-run')) {
            $this->info('Database sanity (dry-run): nessun dato modificato.');
            $this->line("Un solo batch per tabella/tipo, massimo {$batchSize} orfani. I conteggi non sono totali globali.");
            foreach ($query->samples($batchSize) as $table => $types) {
                foreach ($types as $type => $count) {
                    $this->line("{$table} / {$type}: {$count} orfani nel campione (massimo {$batchSize}).");
                }
            }
            $this->reportUnknownTypes($query);

            return self::SUCCESS;
        }

        $result = $sanity->run($batchSize, $maxTime, scheduled: (bool) $this->option('scheduled'));
        if ($result === null) {
            if ($this->option('scheduled')) {
                $this->comment('Database sanity saltata: intervallo minimo non trascorso o pulizia già in esecuzione.');
            } else {
                $this->warn('Database sanity già in esecuzione: nessuna pulizia avviata.');
            }

            return self::SUCCESS;
        }
        $this->reportUnknownTypes($query);
        $this->info('Database sanity: righe eliminate in questa esecuzione.');
        foreach ($result['deleted'] as $table => $types) {
            foreach ($types as $type => $count) {
                $this->line("{$table} / {$type}: {$count} righe eliminate.");
            }
        }
        if ($result['timed_out']) {
            $this->comment('Limite di tempo raggiunto: eseguire nuovamente per continuare.');
        }

        return self::SUCCESS;
    }

    private function reportUnknownTypes(DatabaseSanityQuery $query): void
    {
        foreach ($query->unsupportedCounts() as $table => $types) {
            foreach ($types as $unknown => $count) {
                $type = json_encode((string) $unknown, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                $this->warn("{$table} / tipo non supportato {$type}: {$count} righe conservate (totale per tipo).");
            }
            if (count($types) === 100) {
                $this->comment("{$table}: mostrati al massimo 100 tipi non supportati.");
            }
        }
    }
}
