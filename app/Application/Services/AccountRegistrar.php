<?php

namespace App\Application\Services;

use App\Domain\Accounts\User;
use App\Domain\Accounts\UserSetting;
use App\Domain\Profiles\Profile;
use App\Federation\Actors\Actor;
use App\Federation\Actors\ActorEndpoint;
use App\Federation\Actors\ActorKey;
use App\Infrastructure\Security\RsaKeyPairGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Servizio applicativo che orchestra la creazione di un account locale
 * completo: identita' di autenticazione, profilo pubblico, preferenze e
 * Actor ActivityPub con relativa coppia di chiavi. Viene invocato dai
 * controller (registrazione, installer) affinche' questi restino privi di
 * logica di dominio o federativa, come richiesto dall'architettura.
 */
final class AccountRegistrar
{
    public function __construct(
        private readonly RsaKeyPairGenerator $keyPairGenerator,
    ) {}

    /**
     * @param  array{username: string, email: string, password: string, is_admin?: bool}  $data
     */
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $username = $data['username'];
            $domain = (string) config('openbook.domain');

            $user = User::query()->create([
                'username' => $username,
                'email' => $data['email'],
                'password' => $data['password'],
                'is_admin' => $data['is_admin'] ?? false,
                'status' => User::STATUS_ACTIVE,
            ]);

            Profile::query()->create([
                'user_id' => $user->id,
                'display_name' => $username,
            ]);

            UserSetting::query()->create([
                'user_id' => $user->id,
                'locale' => config('app.locale', 'it'),
                'timezone' => config('app.timezone', 'UTC'),
            ]);

            $actorUri = url('/@'.$username);

            $actor = Actor::query()->create([
                'user_id' => $user->id,
                'type' => Actor::TYPE_PERSON,
                'is_local' => true,
                'preferred_username' => $username,
                'domain' => $domain,
                'uri' => $actorUri,
                'name' => $username,
                'status' => Actor::STATUS_ACTIVE,
            ]);

            $keyPair = $this->keyPairGenerator->generate((int) config('openbook.actor_key_bits', 2048));

            ActorKey::query()->create([
                'actor_id' => $actor->id,
                'public_key' => $keyPair->publicKey,
                'private_key' => $keyPair->privateKey,
            ]);

            ActorEndpoint::query()->create([
                'actor_id' => $actor->id,
                'inbox' => url("/users/{$username}/inbox"),
                'outbox' => url("/users/{$username}/outbox"),
                'followers' => url("/users/{$username}/followers"),
                'following' => url("/users/{$username}/following"),
                'shared_inbox' => url('/inbox'),
            ]);

            return $user;
        });
    }
}
