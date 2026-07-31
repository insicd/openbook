<?php

namespace App\Federation\Serialization;

use App\Federation\Actors\Actor;
use App\Federation\Actors\LocalActorUrls;

/**
 * Traduce un Actor locale nel documento ActivityStreams "Person" (o "Group")
 * restituito dall'endpoint canonico del profilo quando negoziato con
 * Accept: application/activity+json.
 *
 * Per gli Actor locali inbox/outbox/sharedInbox sono sempre ricalcolati da
 * APP_URL: valori obsoleti in "actor_endpoints" (es. hostname precedente)
 * spezzerebbero la federazione con Lemmy, che richiede che l'host dell'id
 * coincida con quello degli endpoint.
 */
final class ActorSerializer
{
    /**
     * @return array<string, mixed>
     */
    public static function serialize(Actor $actor): array
    {
        $actor->loadMissing(['key', 'endpoints', 'user.profile']);

        $profile = $actor->user?->profile;
        $id = $actor->isLocal()
            ? LocalActorUrls::forUsername($actor->preferred_username)['uri']
            : $actor->uri;

        $document = [
            '@context' => [
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/security/v1',
            ],
            'id' => $id,
            'type' => $actor->type === Actor::TYPE_GROUP ? 'Group' : 'Person',
            'preferredUsername' => $actor->preferred_username,
            'name' => $actor->name ?: $actor->preferred_username,
            'summary' => self::renderSummary($profile?->bio ?? $actor->summary),
            'url' => $id,
            'manuallyApprovesFollowers' => $actor->manually_approves_followers,
            'published' => optional($actor->created_at)->toAtomString() ?? now()->toAtomString(),
        ];

        if ($actor->isLocal()) {
            $urls = LocalActorUrls::forUsername($actor->preferred_username);
            $document['inbox'] = $urls['inbox'];
            $document['outbox'] = $urls['outbox'];
            $document['followers'] = $urls['followers'];
            $document['following'] = $urls['following'];
            $document['endpoints'] = ['sharedInbox' => $urls['shared_inbox']];
        } else {
            $endpoints = $actor->endpoints;

            if ($endpoints !== null) {
                $document['inbox'] = $endpoints->inbox;
                $document['outbox'] = $endpoints->outbox;
                $document['followers'] = $endpoints->followers;
                $document['following'] = $endpoints->following;

                if (filled($endpoints->shared_inbox)) {
                    $document['endpoints'] = ['sharedInbox' => $endpoints->shared_inbox];
                }
            }
        }

        $avatarUrl = $profile?->avatarUrl() ?: $actor->icon_url;

        if (filled($avatarUrl)) {
            $document['icon'] = ['type' => 'Image', 'url' => $avatarUrl];
        }

        $coverUrl = $profile?->coverUrl() ?: $actor->image_url;

        if (filled($coverUrl)) {
            $document['image'] = ['type' => 'Image', 'url' => $coverUrl];
        }

        if ($actor->key !== null && filled($actor->key->public_key)) {
            $document['publicKey'] = [
                'id' => $id.'#main-key',
                'owner' => $id,
                'publicKeyPem' => $actor->key->public_key,
            ];
        }

        return $document;
    }

    private static function renderSummary(?string $bio): string
    {
        return filled($bio) ? '<p>'.e($bio).'</p>' : '';
    }
}
