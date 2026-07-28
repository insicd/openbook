<?php

namespace Tests\Concerns;

use App\Application\Services\AccountRegistrar;
use App\Domain\Accounts\User;
use Illuminate\Support\Facades\Hash;

trait CreatesAccounts
{
    private function createFullAccount(string $username, array $overrides = []): User
    {
        $user = app(AccountRegistrar::class)->register(array_merge([
            'username' => $username,
            'email' => $username.'@example.test',
            'password' => Hash::make('Password123'),
        ], $overrides));

        $user->forceFill(['email_verified_at' => now()])->save();

        return $user->fresh(['actor']);
    }
}
