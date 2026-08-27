<?php

namespace Tests\Concerns;

use App\Federation\Actors\Actor;
use App\Federation\Actors\ActorEndpoint;
use App\Federation\Actors\ActorKey;
use App\Federation\Actors\RemoteActorResolver;
use App\Infrastructure\Security\RsaKeyPairGenerator;

trait CreatesRemoteActors
{
    /**
     * Crea un Actor remoto gia' "in cache" (come se fosse stato recuperato
     * in precedenza da {@see RemoteActorResolver}),
     * cosi' i test sull'elaborazione delle attivita' non devono simulare
     * anche il fetch HTTP del documento Actor.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function createRemoteActor(string $username, string $domain = 'remoto.example', array $overrides = []): Actor
    {
        $baseUri = "https://{$domain}/users/{$username}";

        $actor = Actor::query()->create(array_merge([
            'type' => Actor::TYPE_PERSON,
            'is_local' => false,
            'preferred_username' => $username,
            'domain' => $domain,
            'uri' => $baseUri,
            'name' => ucfirst($username),
            'manually_approves_followers' => false,
            'status' => Actor::STATUS_ACTIVE,
            'last_fetched_at' => now(),
            'published_at' => now()->subYears(2),
        ], $overrides));

        $keyPair = (new RsaKeyPairGenerator)->generate(2048);

        ActorKey::query()->create([
            'actor_id' => $actor->id,
            'public_key' => $keyPair->publicKey,
        ]);

        ActorEndpoint::query()->create([
            'actor_id' => $actor->id,
            'inbox' => $baseUri.'/inbox',
            'outbox' => $baseUri.'/outbox',
            'followers' => $baseUri.'/followers',
            'following' => $baseUri.'/following',
            'shared_inbox' => "https://{$domain}/inbox",
        ]);

        return $actor->fresh(['key', 'endpoints']);
    }
}
