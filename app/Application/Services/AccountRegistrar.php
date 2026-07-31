<?php

namespace App\Application\Services;

use App\Domain\Accounts\User;
use App\Domain\Accounts\UserSetting;
use App\Domain\Profiles\Profile;
use App\Federation\Actors\Actor;
use App\Federation\Actors\ActorEndpoint;
use App\Federation\Actors\ActorKey;
use App\Federation\Actors\LocalActorUrls;
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

            $urls = LocalActorUrls::forUsername($username, isGroup: false);

            $actor = Actor::query()->create([
                'user_id' => $user->id,
                'type' => Actor::TYPE_PERSON,
                'is_local' => true,
                'preferred_username' => $username,
                'domain' => $domain,
                'uri' => $urls['uri'],
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
                'inbox' => $urls['inbox'],
                'outbox' => $urls['outbox'],
                'followers' => $urls['followers'],
                'following' => $urls['following'],
                'shared_inbox' => $urls['shared_inbox'],
            ]);

            return $user;
        });
    }
}
