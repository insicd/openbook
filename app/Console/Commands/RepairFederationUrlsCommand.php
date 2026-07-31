<?php

namespace App\Console\Commands;

use App\Federation\Actors\Actor;
use App\Federation\Actors\LocalActorUrls;
use Illuminate\Console\Command;

/**
 * Allinea uri ed endpoint ActivityPub degli Actor locali all'APP_URL corrente
 * e allo schema canonico "/users/{username}" (compatibile con Lemmy/Mastodon).
 */
class RepairFederationUrlsCommand extends Command
{
    protected $signature = 'openbook:repair-federation-urls
        {--dry-run : Mostra le modifiche senza scriverle}';

    protected $description = 'Riscrive uri (/users/...) e inbox/outbox degli Actor locali in base ad APP_URL.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;

        Actor::query()
            ->where('is_local', true)
            ->with('endpoints')
            ->orderBy('preferred_username')
            ->each(function (Actor $actor) use ($dryRun, &$updated): void {
                $urls = LocalActorUrls::forUsername($actor->preferred_username, $actor->isGroup());
                $changes = [];

                if ($actor->uri !== $urls['uri']) {
                    $changes['uri'] = [$actor->uri, $urls['uri']];
                }

                $endpoints = $actor->endpoints;

                if ($endpoints === null) {
                    $this->warn("{$actor->preferred_username}: nessun endpoint, salto.");

                    return;
                }

                foreach (['inbox', 'outbox', 'followers', 'following', 'shared_inbox'] as $field) {
                    if ((string) $endpoints->{$field} !== $urls[$field]) {
                        $changes[$field] = [(string) $endpoints->{$field}, $urls[$field]];
                    }
                }

                if ($changes === []) {
                    return;
                }

                $updated++;
                $this->line($actor->preferred_username.':');

                foreach ($changes as $field => [$from, $to]) {
                    $this->line("  {$field}: {$from} → {$to}");
                }

                if ($dryRun) {
                    return;
                }

                $actor->forceFill([
                    'uri' => $urls['uri'],
                    'domain' => (string) config('openbook.domain'),
                ])->saveQuietly();

                $endpoints->forceFill([
                    'inbox' => $urls['inbox'],
                    'outbox' => $urls['outbox'],
                    'followers' => $urls['followers'],
                    'following' => $urls['following'],
                    'shared_inbox' => $urls['shared_inbox'],
                ])->saveQuietly();
            });

        if ($updated === 0) {
            $this->info('Nessuna correzione necessaria.');
        } elseif ($dryRun) {
            $this->info("Dry-run: {$updated} Actor da aggiornare. Rilancia senza --dry-run per applicare.");
        } else {
            $this->info("Aggiornati {$updated} Actor. Verifica che APP_URL sia https://tuodominio e reinizia le iscrizioni Lemmy ancora in attesa.");
        }

        return self::SUCCESS;
    }
}
