<?php

namespace App\Federation\Support;

/**
 * Normalizzazione conservativa usata soltanto per confrontare identificatori
 * ActivityPub: non modifica mai gli URI salvati o inviati in federazione.
 */
final class ActivityPubUri
{
    public static function same(string $left, string $right): bool
    {
        return self::normalize($left) === self::normalize($right);
    }

    public static function normalize(string $uri): string
    {
        if ($uri === '') {
            return '';
        }

        $parts = parse_url($uri);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return rtrim(rawurldecode($uri), '/');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) && ! self::isDefaultPort($scheme, (int) $parts['port'])
            ? ':'.$parts['port']
            : '';
        $path = rawurldecode((string) ($parts['path'] ?? ''));

        $path = rtrim($path, '/');

        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return $scheme.'://'.$host.$port.$path.$query.$fragment;
    }

    public static function withoutFragment(string $uri): string
    {
        $position = strpos($uri, '#');

        return $position === false ? $uri : substr($uri, 0, $position);
    }

    private static function isDefaultPort(string $scheme, int $port): bool
    {
        return ($scheme === 'https' && $port === 443)
            || ($scheme === 'http' && $port === 80);
    }
}
