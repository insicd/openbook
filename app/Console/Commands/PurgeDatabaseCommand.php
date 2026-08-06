<?php

namespace App\Console\Commands;

use App\Application\Services\DatabaseMaintenanceService;
use App\Infrastructure\Database\SystemSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Pulizia automatica delle tabelle operative (inbox grezzo, job falliti,
 * cache, sessioni). Pensato per essere richiamato da {@see CronCommand}
 * al massimo una volta ogni {@see DatabaseMaintenanceService::RETENTION_HOURS}
 * ore; la stessa logica del pannello admin Database.
 */
class PurgeDatabaseCommand extends Command
{
    public const LAST_RUN_SETTING_KEY = 'database_purge_last_run_at';

    protected $signature = 'openbook:purge-database
        {--force : Esegue subito ignorando l\'intervallo minimo tra due pulizie}';

    protected $description = 'Elimina righe operative piu\' vecchie di 24 ore (inbox elaborato, failed_jobs, cache, sessioni).';

    public function handle(DatabaseMaintenanceService $maintenance): int
    {
        if (! $this->option('force') && ! $this->shouldRun()) {
            $this->comment('Pulizia database saltata: intervallo minimo non ancora trascorso.');

            return self::SUCCESS;
        }

        $deletedByTable = $maintenance->purgeAll(null);
        $total = array_sum($deletedByTable);

        SystemSetting::put(self::LAST_RUN_SETTING_KEY, now()->toIso8601String());

        if ($total === 0) {
            $this->info('Pulizia database completata: nessuna riga eliminata.');
        } else {
            $this->info("Pulizia database completata: {$total} righe eliminate.");
            Log::info('openbook.database_purge', [
                'retention_hours' => DatabaseMaintenanceService::RETENTION_HOURS,
                'deleted' => $deletedByTable,
            ]);
        }

        return self::SUCCESS;
    }

    private function shouldRun(): bool
    {
        $lastRunAt = SystemSetting::get(self::LAST_RUN_SETTING_KEY);

        if ($lastRunAt === null) {
            return true;
        }

        return Carbon::parse($lastRunAt)
            ->addHours(DatabaseMaintenanceService::RETENTION_HOURS)
            ->isPast();
    }
}
