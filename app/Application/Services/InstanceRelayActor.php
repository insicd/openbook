<?php

namespace App\Application\Services;

use App\Federation\Actors\Actor;
use App\Federation\Actors\ActorEndpoint;
use App\Federation\Actors\ActorKey;
use App\Federation\Actors\RelayActorUrls;
use App\Infrastructure\Security\RsaKeyPairGenerator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Crea e recupera l'identita' tecnica usata dalle integrazioni relay. */
final class InstanceRelayActor
{
    public const USERNAME = 'relay';

    public function __construct(
        private readonly RsaKeyPairGenerator $keyPairGenerator,
    ) {}

    public function getOrCreate(): Actor
    {
        $domain = (string) config('openbook.domain');
        $existing = $this->find();

        if ($existing !== null) {
            if (! $existing->isApplication()) {
                throw new RuntimeException('L\'identificativo federato relay@'.$domain.' è già utilizzato da un altro Actor locale.');
            }

            return $this->ensureDependencies($existing);
        }

        return DB::transaction(function () use ($domain): Actor {
            $urls = RelayActorUrls::all();
            $actor = Actor::query()->create([
                'user_id' => null,
                'type' => Actor::TYPE_APPLICATION,
                'is_local' => true,
                'preferred_username' => self::USERNAME,
                'domain' => $domain,
                'uri' => $urls['uri'],
                'name' => (string) config('app.name').' Relay',
                'summary' => null,
                'discoverable' => false,
                'indexable' => false,
                'status' => Actor::STATUS_ACTIVE,
            ]);

            return $this->ensureDependencies($actor);
        });
    }

    public function find(): ?Actor
    {
        return Actor::query()
            ->where('is_local', true)
            ->where('preferred_username', self::USERNAME)
            ->where('domain', (string) config('openbook.domain'))
            ->first();
    }

    private function ensureDependencies(Actor $actor): Actor
    {
        if (! $actor->key()->exists()) {
            $keyPair = $this->keyPairGenerator->generate((int) config('openbook.actor_key_bits', 2048));

            ActorKey::query()->create([
                'actor_id' => $actor->id,
                'public_key' => $keyPair->publicKey,
                'private_key' => $keyPair->privateKey,
            ]);
        }

        $urls = RelayActorUrls::all();
        ActorEndpoint::query()->updateOrCreate(
            ['actor_id' => $actor->id],
            [
                'inbox' => $urls['inbox'],
                'outbox' => $urls['outbox'],
                'followers' => $urls['followers'],
                'following' => $urls['following'],
                'shared_inbox' => $urls['shared_inbox'],
            ],
        );

        return $actor->fresh(['key', 'endpoints']) ?? $actor;
    }
}
