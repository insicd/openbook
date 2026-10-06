<?php

namespace App\Console\Commands;

use App\Application\Queries\DatabaseSanityQuery;
use App\Application\Services\DatabaseSanity;
use Illuminate\Console\Command;

final class DatabaseSanityCommand extends Command
{
    protected $signature = 'openbook:database-sanity
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
            foreach ($query->relations() as $table => $relation) {
                foreach (array_keys($relation['parents']) as $type) {
                    $count = $query->orphans($table, $type, $batchSize)->get()->count();
                    $this->line("{$table} / {$type}: {$count} orfani nel campione (massimo {$batchSize}).");
                }
            }
            $this->reportUnknownTypes($query);

            return self::SUCCESS;
        }

        $lock = fopen(storage_path('framework/cache/database-sanity.lock'), 'c');
        if ($lock === false) {
            $this->error('Impossibile aprire il lock della Database sanity.');

            return self::FAILURE;
        }

        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                $this->warn('Database sanity già in esecuzione: nessuna pulizia avviata.');

                return self::SUCCESS;
            }

            $this->reportUnknownTypes($query);
            $result = $sanity->reconcile($batchSize, $maxTime);
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
        } finally {
            fclose($lock);
        }
    }

    private function reportUnknownTypes(DatabaseSanityQuery $query): void
    {
        foreach (array_keys($query->relations()) as $table) {
            $types = $query->unknownTypes($table)->get();
            foreach ($types as $unknown) {
                $type = json_encode($unknown->type, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                $this->warn("{$table} / tipo non supportato {$type}: {$unknown->row_count} righe conservate (totale per tipo).");
            }
            if ($types->count() === 100) {
                $this->comment("{$table}: mostrati al massimo 100 tipi non supportati.");
            }
        }
    }
}
