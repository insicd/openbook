<?php

namespace App\Support;

final class PostReceivedViaLabel
{
    public static function text(?array $via): ?string
    {
        $community = self::source($via['community'] ?? null);
        $relay = self::source($via['relay'] ?? null);
        $rss = self::source($via['rss'] ?? null);

        return match (true) {
            $community !== null && $relay !== null => __('openbook.posts.received_via_community_relay', compact('community', 'relay')),
            $community !== null => __('openbook.posts.received_via_community', compact('community')),
            $relay !== null => __('openbook.posts.received_via_relay', compact('relay')),
            $rss !== null => __('openbook.posts.received_via_rss', compact('rss')),
            default => null,
        };
    }

    private static function source(mixed $uri): ?string
    {
        if (! is_string($uri)) {
            return null;
        }

        $parts = parse_url($uri);
        if ($parts === false || empty($parts['host']) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }

        // Feed URLs may contain credentials or tokens: display only host and path.
        return $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '');
    }
}
