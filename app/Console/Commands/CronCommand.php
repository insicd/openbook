<?php

namespace App\Console\Commands;

use App\Http\Controllers\CronController;
use Illuminate\Console\Command;

/**
 * Unico punto di ingresso periodico dell'istanza: da richiamare ogni minuto
 * via cron reale ("* * * * * php artisan openbook:cron") oppure tramite
 * l'endpoint web protetto da token per hosting privi di cron o accesso CLI
 * (vedi {@see CronController}). Coordina l'elaborazione dell'inbox, la
 * consegna ActivityPub e Web Push, la conferma dei follow, l'importazione dei
 * feed e la manutenzione periodica del database. I processi potenzialmente
 * piu' lunghi ricevono una porzione del tempo massimo disponibile, cosi'
 * un'unica chiamata resta compatibile con i limiti tipici dell'hosting
 * condiviso senza richiedere worker permanenti.
 */
class CronCommand extends Command
{
    protected $signature = 'openbook:cron
        {--max-time=20 : Tempo massimo di esecuzione complessivo in secondi}';

    protected $description = "Esegue i processi periodici dell'istanza, incluse le consegne ActivityPub e Web Push.";

    public function handle(): int
    {
        $budget = max(3, (int) $this->option('max-time'));
        $slice = max(1, intdiv($budget, 4));

        $this->call('openbook:process-inbox', ['--max-time' => $slice]);
        $this->call('openbook:deliver', ['--max-time' => $slice]);
        $this->call('openbook:deliver-push', ['--max-time' => $slice]);
        // Accept mancanti (tags.pub): conferma via collection followers.
        $this->call('openbook:confirm-outgoing-follows', ['--limit' => 5]);
        $this->call('openbook:fetch-feeds', [
            '--limit' => 5,
            '--max-time' => max(3, $slice),
        ]);
        $this->call('openbook:purge-database');

        return self::SUCCESS;
    }
}
