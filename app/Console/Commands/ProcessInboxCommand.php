<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Elabora le attivita' federate ricevute e gia' verificate (nuovi follow,
 * accettazioni, Mi piace, condivisioni, post/commenti remoti in cache):
 * un worker della coda dedicata "inbox" che si ferma da solo appena la
 * coda e' vuota, pensato per essere richiamato periodicamente da cron
 * (vedi "openbook:cron") invece di restare in esecuzione permanente, non
 * disponibile sulla maggior parte degli hosting condivisi.
 */
class ProcessInboxCommand extends Command
{
    protected $signature = 'openbook:process-inbox
        {--max-time=25 : Tempo massimo di esecuzione in secondi}';

    protected $description = "Elabora le attivita' federate pendenti nell'inbox (coda \"inbox\").";

    public function handle(): int
    {
        return Artisan::call('queue:work', [
            '--queue' => 'inbox',
            '--stop-when-empty' => true,
            '--max-time' => max(1, (int) $this->option('max-time')),
        ], $this->output);
    }
}
