<?php

namespace App\Federation\Inbox;

/** Estrae le emoji dichiarate nel campo ActivityStreams "tag". */
final class RemoteCustomEmoji
{
    /**
     * @param  array<string, mixed>  $document
     * @return array<string, string>
     */
    public static function extract(array $document): array
    {
        $tags = $document['tag'] ?? null;

        if (! is_array($tags)) {
            return [];
        }

        if (isset($tags['type']) || isset($tags['name']) || isset($tags['icon'])) {
            $tags = [$tags];
        }

        $emojis = [];

        foreach (array_slice($tags, 0, 500) as $tag) {
            if (! is_array($tag) || ! RemotePostObject::hasType($tag['type'] ?? null, 'Emoji')) {
                continue;
            }

            $name = $tag['name'] ?? null;
            $icon = $tag['icon'] ?? null;
            $url = self::iconUrl($icon);

            if (! is_string($name)
                || preg_match('/^:[A-Za-z0-9_]{1,100}:$/', $name) !== 1
                || $url === null
                || isset($emojis[$name])) {
                continue;
            }

            $emojis[$name] = $url;

            if (count($emojis) >= 100) {
                break;
            }
        }

        return $emojis;
    }

    private static function iconUrl(mixed $icon): ?string
    {
        if (is_array($icon)) {
            $icon = $icon['url'] ?? $icon['href'] ?? null;
        }

        if (is_array($icon)) {
            $icon = $icon['href'] ?? $icon['url'] ?? null;
        }

        if (! is_string($icon) || strlen($icon) > 2048 || filter_var($icon, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return in_array(strtolower((string) parse_url($icon, PHP_URL_SCHEME)), ['http', 'https'], true)
            ? $icon
            : null;
    }
}
