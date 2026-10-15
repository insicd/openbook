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
 * standard di Laravel. Include i job transazionali sul database del dominio
 * anche quando la coda globale usa un backend separato.
 */
class DeliverActivitiesCommand extends Command
{
    protected $signature = 'openbook:deliver
        {--max-time=25 : Tempo massimo di esecuzione in secondi}';

    protected $description = "Consegna le attivita' federate in uscita accodate (coda \"delivery\").";

    public function handle(): int
    {
        $default = (string) config('queue.default');
        $config = config('queue.connections.'.$default, []);
        $domain = (string) config('database.default');
        $sharesLocalQueue = ($config['driver'] ?? null) === 'database'
            && (($config['connection'] ?? null) ?: $domain) === $domain
            && ($config['table'] ?? null) === 'jobs';
        $connections = $sharesLocalQueue ? [$default] : ['local_delivery'];
        if (! $sharesLocalQueue && ! in_array($config['driver'] ?? null, ['sync', 'null'], true)
            && $default !== 'local_delivery') {
            $connections[] = $default;
        }

        $deadline = microtime(true) + max(1, (int) $this->option('max-time'));
        $status = self::SUCCESS;
        foreach ($connections as $index => $connection) {
            $remaining = (int) ceil($deadline - microtime(true));
            if ($remaining < 1) {
                break;
            }
            $slice = max(1, intdiv($remaining, count($connections) - $index));
            $result = Artisan::call('queue:work', [
                'connection' => $connection,
                '--queue' => 'delivery',
                '--stop-when-empty' => true,
                '--max-time' => $slice,
                '--sleep' => 0,
            ], $this->output);
            $status = max($status, $result);
        }

        return $status;
    }
}
