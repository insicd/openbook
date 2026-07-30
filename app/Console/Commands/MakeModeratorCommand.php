<?php

namespace App\Console\Commands;

use App\Domain\Accounts\User;
use Illuminate\Console\Command;

/**
 * Promuove (o crea via promozione) un moderatore di istanza: accesso al
 * pannello di controllo limitato a segnalazioni e utenti.
 */
class MakeModeratorCommand extends Command
{
    protected $signature = 'openbook:make-moderator
        {--promote= : Nome utente di un account esistente da promuovere a moderatore}';

    protected $description = 'Promuove un account esistente a moderatore di istanza.';

    public function handle(): int
    {
        $promote = $this->option('promote') ?: $this->ask('Nome utente da promuovere a moderatore');

        if (! is_string($promote) || $promote === '') {
            $this->components->error('Indica un nome utente.');

            return self::FAILURE;
        }

        $user = User::query()->where('username', mb_strtolower($promote))->first();

        if ($user === null) {
            $this->components->error("Nessun account trovato con nome utente \"{$promote}\".");

            return self::FAILURE;
        }

        if ($user->is_admin) {
            $this->components->warn("L'account \"{$user->username}\" e gia amministratore (include i poteri di moderazione).");

            return self::SUCCESS;
        }

        $user->forceFill(['is_moderator' => true])->save();

        $this->components->info("L'account \"{$user->username}\" e ora moderatore.");

        return self::SUCCESS;
    }
}
