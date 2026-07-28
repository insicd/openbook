<?php

namespace App\Console\Commands;

use App\Http\Controllers\CronController;
use Illuminate\Console\Command;

/**
 * Unico punto di ingresso periodico dell'istanza: da richiamare ogni minuto
 * via cron reale ("* * * * * php artisan openbook:cron") oppure tramite
 * l'endpoint web protetto da token per hosting privi di cron o accesso CLI
 * (vedi {@see CronController}). Elabora a turno la
 * coda di elaborazione dell'inbox e quella di consegna in uscita, dividendo
 * fra le due il tempo massimo disponibile: cosi' un'unica chiamata resta
 * compatibile con i limiti di esecuzione tipici dell'hosting condiviso.
 */
class CronCommand extends Command
{
    protected $signature = 'openbook:cron
        {--max-time=20 : Tempo massimo di esecuzione complessivo in secondi}';

    protected $description = "Esegue i processi periodici dell'istanza (elaborazione inbox e consegna federata).";

    public function handle(): int
    {
        $budget = max(2, (int) $this->option('max-time'));
        $half = max(1, intdiv($budget, 2));

        $this->call('openbook:process-inbox', ['--max-time' => $half]);
        $this->call('openbook:deliver', ['--max-time' => $half]);

        return self::SUCCESS;
    }
}
