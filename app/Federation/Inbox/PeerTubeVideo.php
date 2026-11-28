<?php

namespace App\Federation\Inbox;

use App\Domain\Posts\VideoEmbedFinder;

final class PeerTubeVideo
{
    private const NAMESPACE_URI = 'https://joinpeertube.org/ns#';

    /**
     * @param  array<string, mixed>  $document
     * @return array{embedUrl: ?string, posterUrl: ?string}|null
     */
    public static function fromDocument(array $document, string $documentUri, string $authorUri): ?array
    {
        if (! RemotePostObject::hasType($document['type'] ?? null, 'Video')
            || ! self::declaresPeerTubeNamespace($document['@context'] ?? null)
            || ! array_key_exists('embedUrl', $document)) {
            return null;
        }

        $source = self::httpsUrl($documentUri);
        $author = self::httpsUrl($authorUri);
        if ($source === null || $author === null || ! self::sameOrigin($source, $author)) {
            return null;
        }

        $embedUrl = $document['embedUrl'];
        if ($embedUrl === null) {
            $path = $source['path'] ?? '';
            $videoId = VideoEmbedFinder::peerTubeVideoId($path);
            if ($videoId === null || ! in_array($path, ['/videos/watch/'.$videoId, '/w/'.$videoId], true)) {
                return null;
            }
        } else {
            $embed = self::httpsUrl($embedUrl);
            $path = $embed['path'] ?? '';
            $videoId = VideoEmbedFinder::peerTubeVideoId($path);
            if ($embed === null || ! self::sameOrigin($source, $embed) || $videoId === null || $path !== '/videos/embed/'.$videoId) {
                return null;
            }
        }

        return ['embedUrl' => $embedUrl, 'posterUrl' => self::poster($document['icon'] ?? null)];
    }

    private static function declaresPeerTubeNamespace(mixed $context): bool
    {
        $entries = is_array($context) && array_is_list($context) ? $context : [$context];
        foreach ($entries as $entry) {
            if ($entry === self::NAMESPACE_URI || (is_array($entry) && in_array(self::NAMESPACE_URI, $entry, true))) {
                return true;
            }
        }

        return false;
    }

    private static function poster(mixed $icon): ?string
    {
        $icons = is_array($icon) && array_is_list($icon) ? $icon : [$icon];
        $poster = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($icons as $candidate) {
            if (! is_array($candidate) || ! RemotePostObject::hasType($candidate['type'] ?? null, 'Image')
                || self::httpsUrl($candidate['url'] ?? null) === null) {
                continue;
            }

            $width = $candidate['width'] ?? null;
            $distance = is_int($width) && $width > 0 ? abs($width - 640) : PHP_INT_MAX - 1;
            if ($distance < $bestDistance) {
                $poster = $candidate['url'];
                $bestDistance = $distance;
            }
        }

        return $poster;
    }

    /** @return array<string, mixed>|null */
    private static function httpsUrl(mixed $url): ?array
    {
        if (! is_string($url) || strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
            || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $parts;
    }

    private static function sameOrigin(array $first, array $second): bool
    {
        return strcasecmp($first['host'], $second['host']) === 0
            && ($first['port'] ?? 443) === ($second['port'] ?? 443);
    }
}
