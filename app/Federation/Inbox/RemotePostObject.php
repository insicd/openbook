<?php

namespace App\Federation\Inbox;

/**
 * Riconosce e normalizza gli oggetti ActivityStreams usati come "post"
 * federati: {@see Note} (Mastodon/Friendica) e {@see Page} (Lemmy e FEP-1b12).
 */
final class RemotePostObject
{
    /**
     * @param  mixed  $type  stringa AS o lista di tipi
     */
    public static function isPostable(mixed $type): bool
    {
        return self::hasType($type, 'Note') || self::hasType($type, 'Page');
    }

    /**
     * Estrae un Note/Page da un'attivita' Create, da un oggetto inline, o
     * restituisce il documento stesso se e' gia' un post.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>|null
     */
    public static function unwrap(array $document): ?array
    {
        if (self::isPostable($document['type'] ?? null)) {
            return $document;
        }

        if (self::hasType($document['type'] ?? null, 'Create') && is_array($document['object'] ?? null)) {
            $inner = $document['object'];

            return self::isPostable($inner['type'] ?? null) ? $inner : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public static function title(array $document): ?string
    {
        $name = $document['name'] ?? null;

        if (! is_string($name)) {
            return null;
        }

        $name = trim($name);

        return $name === '' ? null : mb_substr($name, 0, 255);
    }

    /**
     * Corpo testuale: content HTML ripulito; per i link-post Lemmy spesso
     * manca il content e resta solo "url" (e/o "name").
     *
     * @param  array<string, mixed>  $document
     */
    public static function body(array $document): string
    {
        $body = RemoteContentSanitizer::toPlainText((string) ($document['content'] ?? ''));

        if ($body !== '') {
            return $body;
        }

        $url = $document['url'] ?? null;

        if (is_string($url) && $url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }

        return self::title($document) ?? '';
    }

    /**
     * @param  mixed  $type
     */
    public static function hasType(mixed $type, string $expected): bool
    {
        if ($type === $expected) {
            return true;
        }

        if (! is_array($type)) {
            return false;
        }

        foreach ($type as $item) {
            if ($item === $expected) {
                return true;
            }
        }

        return false;
    }
}
