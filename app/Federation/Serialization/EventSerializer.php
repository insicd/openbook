<?php

namespace App\Federation\Serialization;

use App\Domain\Events\Event;
use App\Domain\Posts\Mention;
use App\Domain\Posts\PostBodyRenderer;
use App\Federation\Actors\LocalActorUrls;

/** Traduce un evento locale nel nucleo ActivityStreams interoperabile. */
final class EventSerializer
{
    /** @return array<string, mixed> */
    public static function serialize(Event $event): array
    {
        $event->loadMissing(['actor.endpoints', 'location', 'media.thumbnail', 'hashtags', 'mentions.actor']);

        $actor = $event->actor;
        $followers = $actor->isLocal()
            ? LocalActorUrls::forUsername($actor->preferred_username, $actor->isGroup())['followers']
            : $actor->endpoints?->followers;
        $mentionUris = $event->mentions
            ->filter(fn (Mention $mention): bool => $mention->actor !== null)
            ->map(fn (Mention $mention): string => $mention->actor->activityPubId())
            ->filter()
            ->values()
            ->all();

        [$to, $cc] = $event->visibility === Event::VISIBILITY_UNLISTED
            ? [array_values(array_filter([$followers])), array_values(array_unique([NoteSerializer::PUBLIC_STREAM, ...$mentionUris]))]
            : [[NoteSerializer::PUBLIC_STREAM], array_values(array_unique(array_filter([$followers, ...$mentionUris])))];

        $object = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $event->uri,
            'type' => 'Event',
            'attributedTo' => $actor->activityPubId(),
            'name' => $event->name,
            'content' => (string) PostBodyRenderer::renderForFederation((string) $event->content),
            'url' => $event->url ?: $event->uri,
            'published' => ($event->published_at ?? $event->created_at)->toAtomString(),
            'startTime' => $event->start_at->toAtomString(),
            'to' => $to,
            'cc' => $cc,
            'sensitive' => $event->sensitive,
            'joinMode' => $event->join_mode,
            'isOnline' => $event->is_online,
        ];

        if ($event->end_at !== null) {
            $object['endTime'] = $event->end_at->toAtomString();
        }

        $object['eventStatus'] = match ($event->status) {
            Event::STATUS_CANCELLED => 'https://schema.org/EventCancelled',
            Event::STATUS_TENTATIVE => 'https://schema.org/EventScheduled',
            Event::STATUS_POSTPONED => 'https://schema.org/EventPostponed',
            default => 'https://schema.org/EventScheduled',
        };

        if ($event->updated_at !== null && $event->created_at !== null && $event->updated_at->greaterThan($event->created_at)) {
            $object['updated'] = $event->updated_at->toAtomString();
        }

        if (filled($event->timezone)) {
            $object['timezone'] = $event->timezone;
        }

        if (filled($event->language)) {
            $object['inLanguage'] = $event->language;
        }

        if (filled($event->external_participation_url)) {
            $object['externalParticipationUrl'] = $event->external_participation_url;
        }

        if ($event->location !== null) {
            $object['location'] = self::location($event);
        }

        $attachments = $event->media->map(fn ($media): array => [
            'type' => 'Image',
            'mediaType' => $media->mime_type,
            'url' => $media->url(),
            'name' => $media->alt_text ?: '',
        ])->values()->all();

        if ($attachments !== []) {
            $object['attachment'] = $attachments;
        }

        $tags = $event->hashtags->map(fn ($hashtag): array => [
            'type' => 'Hashtag',
            'href' => route('hashtags.show', $hashtag->name),
            'name' => '#'.$hashtag->name,
        ])->concat($event->mentions
            ->filter(fn (Mention $mention): bool => $mention->actor !== null)
            ->map(fn (Mention $mention): array => [
                'type' => 'Mention',
                'href' => $mention->actor->activityPubId(),
                'name' => '@'.$mention->actor->handle(),
            ]))
            ->values()
            ->all();

        if ($tags !== []) {
            $object['tag'] = $tags;
        }

        return $object;
    }

    /** @return array<string, mixed> */
    public static function tombstone(Event $event): array
    {
        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $event->uri,
            'type' => 'Tombstone',
            'formerType' => 'Event',
            'deleted' => ($event->deleted_at ?? $event->updated_at ?? now())->toAtomString(),
        ];
    }

    /** @return array<string, mixed> */
    private static function location(Event $event): array
    {
        $location = $event->location;
        $place = [
            'type' => 'Place',
            'name' => $location->name ?: $location->locality,
        ];
        $address = array_filter([
            'type' => 'PostalAddress',
            'streetAddress' => $location->street_address ?: $location->address,
            'addressLocality' => $location->locality,
            'addressRegion' => $location->region,
            'postalCode' => $location->postal_code,
            'addressCountry' => $location->country_code,
            'addressCountryName' => $location->country_name,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        if (count($address) > 1) {
            $place['address'] = $address;
        }

        if ($location->latitude !== null && $location->longitude !== null) {
            $place['latitude'] = $location->latitude;
            $place['longitude'] = $location->longitude;
        }

        return $place;
    }
}
