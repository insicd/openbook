<?php

namespace Database\Seeders;

use App\Application\Services\AccountRegistrar;
use App\Infrastructure\Security\RsaKeyPairGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Crea un account amministratore di sviluppo (solo ambiente locale).
     * In produzione l'amministratore viene creato dall'installer guidato o
     * dal comando "openbook:make-admin".
     */
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }

        $registrar = new AccountRegistrar(new RsaKeyPairGenerator((int) config('openbook.actor_key_bits', 2048)));

        $registrar->register([
            'username' => 'admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
            'is_admin' => true,
        ]);
    }
}
