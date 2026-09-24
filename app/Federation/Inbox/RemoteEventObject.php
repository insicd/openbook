<?php

namespace App\Federation\Inbox;

use App\Domain\Events\Event;
use App\Domain\Posts\Hashtag;
use App\Federation\Serialization\NoteSerializer;
use App\Federation\Support\ActivityPubTimestamp;
use Illuminate\Support\Carbon;
use Throwable;

/** Riconosce e normalizza un oggetto ActivityStreams Event remoto. */
final class RemoteEventObject
{
    /** @param array<string, mixed> $document */
    public static function unwrap(array $document): ?array
    {
        if (self::isEvent($document['type'] ?? null)) {
            return $document;
        }

        if (is_array($document['object'] ?? null) && self::isEvent($document['object']['type'] ?? null)) {
            return $document['object'];
        }

        return null;
    }

    public static function isEvent(mixed $type): bool
    {
        return RemotePostObject::hasType($type, 'Event');
    }

    /** @param array<string, mixed> $document */
    public static function uri(array $document): ?string
    {
        return self::httpsUrl($document['id'] ?? null, 255);
    }

    /** @param array<string, mixed> $document */
    public static function creatorUri(array $document): ?string
    {
        return self::actorUri($document['actor'] ?? null);
    }

    /** @param array<string, mixed> $document */
    public static function name(array $document): ?string
    {
        return self::plainText($document['name'] ?? null, 500);
    }

    /** @param array<string, mixed> $document */
    public static function content(array $document): ?string
    {
        return self::longPlainText($document['content'] ?? null);
    }

    /** @param array<string, mixed> $document */
    public static function summary(array $document): ?string
    {
        return self::longPlainText($document['summary'] ?? null);
    }

    /** @param array<string, mixed> $document */
    public static function startAt(array $document): ?Carbon
    {
        return self::date($document['startTime'] ?? null);
    }

    /** @param array<string, mixed> $document */
    public static function endAt(array $document, Carbon $startAt): ?Carbon
    {
        $endAt = self::date($document['endTime'] ?? null);

        return $endAt !== null && $endAt->greaterThan($startAt) ? $endAt : null;
    }

    /** @param array<string, mixed> $document */
    public static function utcOffsetMinutes(array $document): ?int
    {
        $value = $document['startTime'] ?? null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return (int) (Carbon::parse($value)->getOffset() / 60);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $document */
    public static function timezone(array $document): ?string
    {
        $timezone = self::scalarString($document['timezone'] ?? null, 64);

        if ($timezone === null) {
            return null;
        }

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : null;
    }

    /** @param array<string, mixed> $document */
    public static function visibility(array $document): string
    {
        $to = self::audienceList($document['to'] ?? null);
        $cc = self::audienceList($document['cc'] ?? null);

        if (self::addressesPublic($to)) {
            return Event::VISIBILITY_PUBLIC;
        }

        if (self::addressesPublic($cc)) {
            return Event::VISIBILITY_UNLISTED;
        }

        $all = array_merge($to, $cc);

        if ($all === []) {
            return Event::VISIBILITY_DIRECT;
        }

        foreach ($all as $address) {
            if (str_ends_with(rtrim($address, '/'), '/followers')) {
                return Event::VISIBILITY_FOLLOWERS;
            }
        }

        return Event::VISIBILITY_DIRECT;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<string>
     */
    public static function audienceActorUris(array $document): array
    {
        $uris = [];

        foreach (['to', 'cc'] as $field) {
            foreach (self::audienceList($document[$field] ?? null) as $address) {
                if (self::addressesPublic([$address]) || str_ends_with(rtrim($address, '/'), '/followers')) {
                    continue;
                }

                if (self::httpsUrl($address, 2048) !== null) {
                    $uris[] = $address;
                }
            }
        }

        return array_values(array_unique($uris));
    }

    /** @param array<string, mixed> $document */
    public static function status(array $document): string
    {
        $raw = self::scalarString(
            $document['ical:status'] ?? $document['eventStatus'] ?? $document['status'] ?? null,
            100,
        );
        $status = $raw !== null ? strtoupper((string) preg_replace('/^.*[#:\/]/', '', $raw)) : null;

        if ($status !== null && (str_contains($status, 'CANCELLED') || str_contains($status, 'CANCELED'))) {
            return Event::STATUS_CANCELLED;
        }

        if ($status !== null && str_contains($status, 'TENTATIVE')) {
            return Event::STATUS_TENTATIVE;
        }

        if ($status !== null && str_contains($status, 'POSTPONED')) {
            return Event::STATUS_POSTPONED;
        }

        return Event::STATUS_SCHEDULED;
    }

    /** @param array<string, mixed> $document */
    public static function joinMode(array $document): ?string
    {
        $raw = self::scalarString($document['joinMode'] ?? null, 100);

        if ($raw === null) {
            return null;
        }

        $mode = strtolower((string) preg_replace('/^.*[#:\/]/', '', $raw));

        return in_array($mode, ['free', 'restricted', 'external', 'none', 'invite'], true)
            ? $mode
            : null;
    }

    /** @param array<string, mixed> $document */
    public static function location(array $document): ?array
    {
        $place = $document['location'] ?? null;

        if (is_array($place) && array_is_list($place)) {
            foreach ($place as $location) {
                if (is_array($location) && RemotePostObject::hasType($location['type'] ?? null, 'Place')) {
                    $place = $location;

                    break;
                }
            }
        }

        if (! is_array($place) || ! RemotePostObject::hasType($place['type'] ?? null, 'Place')) {
            return null;
        }

        $addressValue = $place['address'] ?? null;
        $address = is_string($addressValue) ? self::scalarString($addressValue, 2000) : null;
        $postal = is_array($addressValue) ? $addressValue : [];
        $latitude = self::coordinate($place['latitude'] ?? null, -90, 90);
        $longitude = self::coordinate($place['longitude'] ?? null, -180, 180);

        if ($latitude === null || $longitude === null) {
            $latitude = null;
            $longitude = null;
        }

        $country = self::plainText($postal['addressCountry'] ?? null, 200);
        $countryCode = $country !== null && mb_strlen($country) <= 8 ? strtoupper($country) : null;
        $countryName = self::plainText($postal['addressCountryName'] ?? null, 200)
            ?? ($countryCode === null ? $country : null);

        $location = [
            'geo_city_id' => null,
            'remote_uri' => self::httpsUrl($place['id'] ?? null, 2048),
            'url' => self::httpsUrl($place['url'] ?? null, 2048),
            'name' => self::plainText($place['name'] ?? null, 500),
            'address' => $address,
            'street_address' => self::plainText($postal['streetAddress'] ?? null, 500),
            'locality' => self::plainText($postal['addressLocality'] ?? null, 200),
            'region' => self::plainText($postal['addressRegion'] ?? null, 200),
            'postal_code' => self::scalarString($postal['postalCode'] ?? null, 32),
            'country_code' => $countryCode,
            'country_name' => $countryName,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'source' => 'remote',
        ];

        foreach ($location as $key => $value) {
            if (! in_array($key, ['geo_city_id', 'source'], true) && $value !== null) {
                return $location;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<array{url: string, name: ?string, media_type: ?string}>
     */
    public static function links(array $document): array
    {
        $attachments = $document['attachment'] ?? null;

        if (! is_array($attachments)) {
            return [];
        }

        if (isset($attachments['type']) || isset($attachments['href'])) {
            $attachments = [$attachments];
        }

        $links = [];

        foreach (array_slice($attachments, 0, 100) as $attachment) {
            if (! is_array($attachment) || ! RemotePostObject::hasType($attachment['type'] ?? null, 'Link')) {
                continue;
            }

            $url = self::httpsUrl($attachment['href'] ?? $attachment['url'] ?? null, 2048);

            if ($url === null || isset($links[$url])) {
                continue;
            }

            $links[$url] = [
                'url' => $url,
                'name' => self::plainText($attachment['name'] ?? null, 500),
                'media_type' => self::scalarString($attachment['mediaType'] ?? null, 100),
            ];
        }

        return array_values($links);
    }

    /** @param array<string, mixed> $document @return list<string> */
    public static function hashtags(array $document): array
    {
        $tags = $document['tag'] ?? null;

        if (! is_array($tags)) {
            return [];
        }

        if (isset($tags['type']) || isset($tags['name'])) {
            $tags = [$tags];
        }

        $names = [];

        foreach (array_slice($tags, 0, 500) as $tag) {
            if (! is_array($tag)
                || ! RemotePostObject::hasType($tag['type'] ?? null, 'Hashtag')
                || ! is_string($tag['name'] ?? null)) {
                continue;
            }

            $name = Hashtag::normalize($tag['name']);

            if (Hashtag::isValidName($name)) {
                $names[$name] = $name;
            }
        }

        return array_values($names);
    }

    /** @param array<string, mixed> $document */
    public static function participantCount(array $document): ?int
    {
        return self::nonNegativeInteger($document['participantCount'] ?? null)
            ?? RemotePostObject::collectionTotalItems($document['participants'] ?? $document['attendees'] ?? null);
    }

    /** @param array<string, mixed> $document */
    public static function likesCount(array $document): ?int
    {
        return RemotePostObject::collectionTotalItems($document['likes'] ?? null);
    }

    /** @param array<string, mixed> $document */
    public static function publishedAt(array $document): ?Carbon
    {
        return self::date($document['published'] ?? null);
    }

    /** @param array<string, mixed> $document */
    public static function updatedAt(array $document): ?Carbon
    {
        return self::date($document['updated'] ?? null);
    }

    /** @param array<string, mixed> $document */
    public static function isOnline(array $document): bool
    {
        return filter_var($document['isOnline'] ?? false, FILTER_VALIDATE_BOOL)
            || self::externalParticipationUrl($document) !== null;
    }

    /** @param array<string, mixed> $document */
    public static function externalParticipationUrl(array $document): ?string
    {
        $url = self::httpsUrl($document['externalParticipationUrl'] ?? null, 2048);

        if ($url !== null) {
            return $url;
        }

        $locations = $document['location'] ?? null;
        $locations = is_array($locations) && array_is_list($locations) ? $locations : [$locations];

        foreach ($locations as $location) {
            if (is_array($location) && RemotePostObject::hasType($location['type'] ?? null, 'VirtualLocation')) {
                $url = self::httpsUrl($location['url'] ?? null, 2048);

                if ($url !== null) {
                    return $url;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $document */
    public static function primaryUrl(array $document): ?string
    {
        return self::httpsUrl($document['url'] ?? null, 2048);
    }

    /** @param array<string, mixed> $document */
    public static function language(array $document): ?string
    {
        return self::scalarString($document['inLanguage'] ?? null, 35);
    }

    /** @param array<string, mixed> $document */
    public static function category(array $document): ?string
    {
        return self::plainText($document['category'] ?? null, 100);
    }

    /** @param array<string, mixed> $document */
    public static function seriesUri(array $document): ?string
    {
        return self::httpsUrl($document['eventSeries'] ?? $document['series'] ?? null, 255);
    }

    /** @param array<string, mixed> $document */
    public static function sensitive(array $document): bool
    {
        return filter_var($document['sensitive'] ?? false, FILTER_VALIDATE_BOOL);
    }

    private static function actorUri(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['id'] ?? null;
        }

        return self::httpsUrl($value, 2048);
    }

    private static function httpsUrl(mixed $value, int $maxLength): ?string
    {
        if (is_array($value)) {
            $value = $value['href'] ?? $value['id'] ?? $value['url'] ?? null;
        }

        if (! is_string($value) || $value === '' || strlen($value) > $maxLength || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return strtolower((string) parse_url($value, PHP_URL_SCHEME)) === 'https' ? $value : null;
    }

    private static function plainText(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim(RemoteContentSanitizer::toPlainText($value));

        return $text !== '' ? mb_substr($text, 0, $maxLength) : null;
    }

    private static function longPlainText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim(RemoteContentSanitizer::toPlainText($value));

        return $text !== '' ? $text : null;
    }

    private static function scalarString(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? mb_substr($value, 0, $maxLength) : null;
    }

    private static function coordinate(mixed $value, float $minimum, float $maximum): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $coordinate = (float) $value;

        return is_finite($coordinate) && $coordinate >= $minimum && $coordinate <= $maximum
            ? $coordinate
            : null;
    }

    private static function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return ActivityPubTimestamp::normalize(Carbon::parse($value));
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private static function audienceList(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) && $item !== ''));
    }

    /** @param list<string> $addresses */
    private static function addressesPublic(array $addresses): bool
    {
        foreach ($addresses as $address) {
            if (in_array($address, [NoteSerializer::PUBLIC_STREAM, 'as:Public', 'Public'], true)) {
                return true;
            }
        }

        return false;
    }

    private static function nonNegativeInteger(mixed $value): ?int
    {
        if ((is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value >= 0) {
            return (int) $value;
        }

        return null;
    }
}
