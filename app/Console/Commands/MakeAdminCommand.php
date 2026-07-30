<?php

namespace App\Console\Commands;

use App\Application\Services\AccountRegistrar;
use App\Domain\Accounts\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Fallback a riga di comando per creare (o promuovere) un amministratore
 * quando l'installer web non e' stato usato oppure quando serve un secondo
 * account con privilegi amministrativi.
 */
class MakeAdminCommand extends Command
{
    protected $signature = 'openbook:make-admin
        {--username= : Nome utente del nuovo amministratore}
        {--email= : Indirizzo email del nuovo amministratore}
        {--password= : Password del nuovo amministratore (se omessa viene richiesta)}
        {--promote= : Nome utente di un account esistente da promuovere ad amministratore}';

    protected $description = 'Crea un nuovo account amministratore oppure promuove un account esistente.';

    public function handle(AccountRegistrar $registrar): int
    {
        $promote = $this->option('promote');

        if ($promote !== null) {
            return $this->promoteExisting($promote);
        }

        $username = $this->option('username') ?: $this->ask('Nome utente');
        $email = $this->option('email') ?: $this->ask('Email');
        $password = $this->option('password') ?: $this->secret('Password (minimo 8 caratteri, maiuscole, minuscole e numeri)');

        $validator = Validator::make(
            ['username' => $username, 'email' => $email, 'password' => $password],
            [
                'username' => ['required', 'string', 'min:3', 'max:32', 'regex:/^[a-z0-9_]+$/', 'unique:users,username'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', Password::min(8)->mixedCase()->numbers()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $data = $validator->validated();

        $user = $registrar->register([
            'username' => mb_strtolower($data['username']),
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'is_admin' => true,
        ]);

        $user->forceFill([
            'email_verified_at' => now(),
            'is_moderator' => true,
        ])->save();

        $this->components->info("Amministratore \"{$user->username}\" creato correttamente.");

        return self::SUCCESS;
    }

    private function promoteExisting(string $username): int
    {
        $user = User::query()->where('username', mb_strtolower($username))->first();

        if ($user === null) {
            $this->components->error("Nessun account trovato con nome utente \"{$username}\".");

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => true, 'is_moderator' => true])->save();

        $this->components->info("L'account \"{$user->username}\" e ora amministratore.");

        return self::SUCCESS;
    }
}
