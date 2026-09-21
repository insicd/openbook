<?php

namespace App\Domain\Feeds;

use App\Federation\Actors\Actor;
use App\Federation\Actors\ActorEndpoint;
use App\Federation\Actors\ActorKey;
use App\Infrastructure\Security\RsaKeyPairGenerator;

/**
 * Completa l'identita' ActivityPub di un Actor feed: URI locale, chiavi e
 * endpoint pubblici. Senza questi documenti le altre istanze, ricevendo un
 * Announce, recuperano l'URL dell'articolo e mostrano solo un link.
 */
final class FeedActorIdentity
{
    public function __construct(
        private readonly RsaKeyPairGenerator $keyPairGenerator,
    ) {}

    public function ensure(Actor $actor): Actor
    {
        if (! $actor->isFeed()) {
            return $actor;
        }

        $domain = (string) config('openbook.domain');
        $urls = FeedActorUrls::for($actor);
        $dirty = false;

        if ($actor->uri !== $urls['uri'] || $actor->domain !== $domain) {
            $actor->forceFill([
                'uri' => $urls['uri'],
                'domain' => $domain,
                'discoverable' => false,
                'indexable' => false,
            ])->save();
            $dirty = true;
        }

        $actor->loadMissing(['key', 'endpoints']);

        if ($actor->key === null) {
            $keyPair = $this->keyPairGenerator->generate((int) config('openbook.actor_key_bits', 2048));

            ActorKey::query()->create([
                'actor_id' => $actor->id,
                'public_key' => $keyPair->publicKey,
                'private_key' => $keyPair->privateKey,
            ]);
            $dirty = true;
        }

        if ($actor->endpoints === null) {
            ActorEndpoint::query()->create([
                'actor_id' => $actor->id,
                'inbox' => $urls['inbox'],
                'outbox' => $urls['outbox'],
                'followers' => $urls['followers'],
                'following' => $urls['following'],
                'shared_inbox' => $urls['shared_inbox'],
            ]);
            $dirty = true;
        } else {
            $endpoints = $actor->endpoints;
            $updates = [];

            foreach (['inbox', 'outbox', 'followers', 'following', 'shared_inbox'] as $field) {
                if ($endpoints->{$field} !== $urls[$field]) {
                    $updates[$field] = $urls[$field];
                }
            }

            if ($updates !== []) {
                $endpoints->forceFill($updates)->save();
                $dirty = true;
            }
        }

        if (! $dirty) {
            return $actor;
        }

        return $actor->fresh(['key', 'endpoints', 'feedSource']) ?? $actor;
    }
}
