<?php

namespace App\Federation\Serialization;

use App\Federation\Actors\Actor;

/**
 * Traduce un Actor locale nel documento ActivityStreams "Person" (o "Group",
 * quando le community arriveranno in Fase 5) restituito dall'endpoint
 * canonico del profilo quando negoziato con Accept: application/activity+json.
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

        $document = [
            '@context' => [
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/security/v1',
            ],
            'id' => $actor->uri,
            'type' => $actor->type === Actor::TYPE_GROUP ? 'Group' : 'Person',
            'preferredUsername' => $actor->preferred_username,
            'name' => $actor->name ?: $actor->preferred_username,
            'summary' => self::renderSummary($profile?->bio ?? $actor->summary),
            'url' => $actor->uri,
            'manuallyApprovesFollowers' => $actor->manually_approves_followers,
            'published' => optional($actor->created_at)->toAtomString() ?? now()->toAtomString(),
        ];

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
                'id' => $actor->uri.'#main-key',
                'owner' => $actor->uri,
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
