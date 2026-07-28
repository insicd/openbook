<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Consegna le attivita' in uscita accodate verso le inbox remote (Follow,
 * Accept, Reject, Undo, Create, Update, Delete, Like, Announce): un worker
 * della coda dedicata "delivery" che si ferma da solo appena la coda e'
 * vuota. I tentativi falliti restano nella coda con backoff crescente
 * (vedi "config('openbook.delivery')") finche' non superano il numero
 * massimo di tentativi, dopodiche' finiscono nella tabella "failed_jobs"
 * standard di Laravel.
 */
class DeliverActivitiesCommand extends Command
{
    protected $signature = 'openbook:deliver
        {--max-time=25 : Tempo massimo di esecuzione in secondi}';

    protected $description = "Consegna le attivita' federate in uscita accodate (coda \"delivery\").";

    public function handle(): int
    {
        return Artisan::call('queue:work', [
            '--queue' => 'delivery',
            '--stop-when-empty' => true,
            '--max-time' => max(1, (int) $this->option('max-time')),
        ], $this->output);
    }
}
