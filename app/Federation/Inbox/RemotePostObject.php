<?php

namespace App\Federation\Inbox;

/**
 * Riconosce e normalizza gli oggetti ActivityStreams usati come "post"
 * federati: Note (Mastodon/Misskey/Friendica/Pixelfed/NodeBB), Page (Lemmy),
 * Article (WordPress ActivityPub, WriteFreely), Video (PeerTube), Image
 * (alcuni client fotografici).
 */
final class RemotePostObject
{
    /**
     * Tipi AS accettati come post di primo livello (non necessariamente
     * come risposta: le replies restano tipicamente Note).
     *
     * @var list<string>
     */
    private const POSTABLE_TYPES = [
        'Note',
        'Page',
        'Article',
        'Video',
        'Image',
    ];

    /**
     * @param  mixed  $type  stringa AS, IRI completo o lista di tipi
     */
    public static function isPostable(mixed $type): bool
    {
        foreach (self::POSTABLE_TYPES as $expected) {
            if (self::hasType($type, $expected)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Estrae un oggetto postabile da un'attivita' Create, da un oggetto
     * inline, o restituisce il documento stesso se e' gia' un post.
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
     * Corpo testuale: content HTML ripulito, poi contentMap / source /
     * summary / url canonico / name. Cosi' Article senza content breve,
     * Video PeerTube e link-post Lemmy restano leggibili.
     *
     * @param  array<string, mixed>  $document
     */
    public static function body(array $document): string
    {
        $body = RemoteContentSanitizer::toPlainText(self::rawContent($document));

        if ($body !== '') {
            return $body;
        }

        $summary = $document['summary'] ?? null;

        if (is_string($summary) && trim($summary) !== '' && ! (bool) ($document['sensitive'] ?? false)) {
            $fromSummary = RemoteContentSanitizer::toPlainText($summary);

            if ($fromSummary !== '') {
                return $fromSummary;
            }
        }

        $url = self::primaryUrl($document);

        if ($url !== null) {
            return $url;
        }

        return self::title($document) ?? '';
    }

    /**
     * URL "pagina" preferito (text/html) oppure prima URL http(s) utile
     * da stringa, Link, o lista di Link — tipico di PeerTube e Lemmy.
     *
     * @param  array<string, mixed>  $document
     */
    public static function primaryUrl(array $document): ?string
    {
        return self::extractUrl($document['url'] ?? null, preferHtml: true)
            ?? self::extractUrl($document['id'] ?? null, preferHtml: false);
    }

    /**
     * True se il firmatario e' tra gli autori dichiarati in attributedTo
     * (stringa, oggetto con id, o lista Person+Group come su PeerTube).
     */
    public static function authorMatches(mixed $attributedTo, string $signerUri): bool
    {
        return in_array($signerUri, self::actorUris($attributedTo), true);
    }

    /**
     * Autore primario da usare in cache locale: preferisce $preferredUri
     * se presente tra gli attributed, altrimenti il primo Person, altrimenti
     * il primo URI della lista.
     */
    public static function primaryAuthorUri(mixed $attributedTo, ?string $preferredUri = null): ?string
    {
        $uris = self::actorUris($attributedTo);

        if ($uris === []) {
            return null;
        }

        if ($preferredUri !== null && in_array($preferredUri, $uris, true)) {
            return $preferredUri;
        }

        $personUri = self::firstActorUriOfType($attributedTo, 'Person');

        return $personUri ?? $uris[0];
    }

    /**
     * @return list<string>
     */
    public static function actorUris(mixed $attributedTo): array
    {
        if (is_string($attributedTo) && $attributedTo !== '') {
            return [$attributedTo];
        }

        if (! is_array($attributedTo)) {
            return [];
        }

        if (is_string($attributedTo['id'] ?? null) && $attributedTo['id'] !== '') {
            return [$attributedTo['id']];
        }

        $uris = [];

        foreach ($attributedTo as $item) {
            if (is_string($item) && $item !== '') {
                $uris[] = $item;

                continue;
            }

            if (is_array($item) && is_string($item['id'] ?? null) && $item['id'] !== '') {
                $uris[] = $item['id'];
            }
        }

        return array_values(array_unique($uris));
    }

    /**
     * Allegati immagine da mostrare in galleria (Image/Document con mime
     * immagine, oppure Image top-level). Non scarica i file: solo URL https.
     *
     * @param  array<string, mixed>  $document
     * @return list<array{url: string, mime: string|null, alt: string|null}>
     */
    public static function imageAttachments(array $document): array
    {
        $found = [];

        $attachments = $document['attachment'] ?? null;

        if (is_array($attachments)) {
            // attachment puo' essere un singolo oggetto.
            if (isset($attachments['type']) || isset($attachments['url']) || isset($attachments['href'])) {
                $attachments = [$attachments];
            }

            foreach ($attachments as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $descriptor = self::imageDescriptorFromObject($item);

                if ($descriptor !== null) {
                    $found[$descriptor['url']] = $descriptor;
                }
            }
        }

        // Image top-level (raro) o icon/image di anteprima Video/Article.
        if (self::hasType($document['type'] ?? null, 'Image')) {
            $descriptor = self::imageDescriptorFromObject($document);

            if ($descriptor !== null) {
                $found[$descriptor['url']] = $descriptor;
            }
        }

        foreach (['image', 'icon'] as $field) {
            $preview = $document[$field] ?? null;

            if (is_array($preview)) {
                $descriptor = self::imageDescriptorFromObject($preview);

                if ($descriptor !== null) {
                    $found[$descriptor['url']] = $descriptor;
                }
            } elseif (is_string($preview) && self::isSafeHttpUrl($preview) && self::looksLikeImageUrl($preview)) {
                $found[$preview] = ['url' => $preview, 'mime' => null, 'alt' => null];
            }
        }

        return array_values($found);
    }

    /**
     * URI del post citato (quote), se presente. Ordine di preferenza allineato
     * a Mastodon: {@code quote} (FEP-044f), poi legacy {@code quoteUrl} /
     * {@code quoteUri} / {@code _misskey_quote}.
     *
     * @param  array<string, mixed>  $document
     */
    public static function quoteUri(array $document): ?string
    {
        foreach (['quote', 'quoteUrl', 'quoteUri', '_misskey_quote'] as $key) {
            $uri = self::valueOrId($document[$key] ?? null);

            if ($uri !== null) {
                return $uri;
            }
        }

        return null;
    }

    /**
     * Oggetto Note/Page incorporato in {@code quote} (FEP-044f), se presente.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>|null
     */
    public static function embeddedQuote(array $document): ?array
    {
        $quote = $document['quote'] ?? null;

        if (! is_array($quote)) {
            return null;
        }

        if (self::isPostable($quote['type'] ?? null)) {
            return $quote;
        }

        foreach ($quote as $item) {
            if (is_array($item) && self::isPostable($item['type'] ?? null)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Rimuove dal corpo il fallback testuale della citazione (link nudo o
     * riga "RE: …") cosi' in UI resta solo la card del post citato.
     */
    public static function stripQuoteFallbackFromBody(string $body, string $quoteUri): string
    {
        $body = trim($body);

        if ($body === '' || $quoteUri === '') {
            return $body;
        }

        $escaped = preg_quote($quoteUri, '/');

        $patterns = [
            '/(?:\r?\n)+\s*RE:\s*'.$escaped.'\s*$/iu',
            '/(?:\r?\n)+\s*RE:\s*.{0,200}'.$escaped.'.{0,200}$/iu',
            '/(?:\r?\n)+\s*\[[^\]]*\]\('.$escaped.'\)\s*$/u',
            '/(?:\r?\n)+\s*'.$escaped.'\s*$/u',
            '/^RE:\s*'.$escaped.'\s*$/iu',
            '/^RE:\s*.{0,200}'.$escaped.'.{0,200}$/iu',
            '/^'.$escaped.'\s*$/u',
            '/^\[[^\]]*\]\('.$escaped.'\)\s*$/u',
        ];

        foreach ($patterns as $pattern) {
            $stripped = preg_replace($pattern, '', $body);

            if (is_string($stripped) && $stripped !== $body) {
                $body = trim($stripped);
            }
        }

        return $body;
    }

    private static function valueOrId(mixed $value): ?string
    {
        if (is_string($value) && self::isSafeHttpUrl($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return null;
        }

        if (is_string($value['id'] ?? null) && self::isSafeHttpUrl($value['id'])) {
            return $value['id'];
        }

        if (is_string($value['href'] ?? null) && self::isSafeHttpUrl($value['href'])) {
            return $value['href'];
        }

        foreach ($value as $item) {
            $uri = self::valueOrId($item);

            if ($uri !== null) {
                return $uri;
            }
        }

        return null;
    }

    /**
     * @param  mixed  $type
     */
    public static function hasType(mixed $type, string $expected): bool
    {
        if (self::typeName($type) === $expected) {
            return true;
        }

        if (! is_array($type)) {
            return false;
        }

        foreach ($type as $item) {
            if (self::typeName($item) === $expected) {
                return true;
            }
        }

        return false;
    }

    private static function typeName(mixed $type): ?string
    {
        if (! is_string($type) || $type === '') {
            return null;
        }

        if (str_contains($type, '#')) {
            return substr((string) strrchr($type, '#'), 1) ?: null;
        }

        if (str_starts_with($type, 'as:')) {
            return substr($type, 3) ?: null;
        }

        if (str_contains($type, '/')) {
            $base = basename($type);

            return $base !== '' ? $base : null;
        }

        return $type;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private static function rawContent(array $document): string
    {
        $content = $document['content'] ?? null;

        if (is_string($content) && $content !== '') {
            return $content;
        }

        $contentMap = $document['contentMap'] ?? null;

        if (is_array($contentMap)) {
            foreach ($contentMap as $value) {
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        $source = $document['source'] ?? null;

        if (is_array($source) && is_string($source['content'] ?? null) && $source['content'] !== '') {
            return $source['content'];
        }

        return '';
    }

    private static function extractUrl(mixed $value, bool $preferHtml): ?string
    {
        if (is_string($value) && self::isSafeHttpUrl($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return null;
        }

        if (is_string($value['href'] ?? null) && self::isSafeHttpUrl($value['href'])) {
            return $value['href'];
        }

        if (is_string($value['url'] ?? null) && self::isSafeHttpUrl($value['url'])) {
            return $value['url'];
        }

        $fallback = null;

        foreach ($value as $item) {
            $href = null;
            $mediaType = null;

            if (is_string($item) && self::isSafeHttpUrl($item)) {
                $href = $item;
            } elseif (is_array($item)) {
                if (is_string($item['href'] ?? null) && self::isSafeHttpUrl($item['href'])) {
                    $href = $item['href'];
                } elseif (is_string($item['url'] ?? null) && self::isSafeHttpUrl($item['url'])) {
                    $href = $item['url'];
                }

                $mediaType = is_string($item['mediaType'] ?? null) ? strtolower($item['mediaType']) : null;
            }

            if ($href === null) {
                continue;
            }

            // PeerTube: Link text/html = pagina /w/... da mettere in body per l'embed.
            if ($preferHtml && $mediaType === 'text/html') {
                return $href;
            }

            $fallback ??= $href;
        }

        return $fallback;
    }

    private static function firstActorUriOfType(mixed $attributedTo, string $type): ?string
    {
        if (! is_array($attributedTo) || isset($attributedTo['id'])) {
            return null;
        }

        foreach ($attributedTo as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (self::hasType($item['type'] ?? null, $type) && is_string($item['id'] ?? null) && $item['id'] !== '') {
                return $item['id'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array{url: string, mime: string|null, alt: string|null}|null
     */
    private static function imageDescriptorFromObject(array $object): ?array
    {
        $type = $object['type'] ?? null;
        $mime = is_string($object['mediaType'] ?? null) ? strtolower($object['mediaType']) : null;
        $alt = null;

        if (is_string($object['name'] ?? null)) {
            $alt = mb_substr(trim($object['name']), 0, 1000) ?: null;
        }

        $url = self::extractUrl($object['url'] ?? null, preferHtml: false)
            ?? (is_string($object['href'] ?? null) ? $object['href'] : null);

        if ($url === null && is_string($object['id'] ?? null) && self::looksLikeImageUrl($object['id'])) {
            $url = $object['id'];
        }

        if ($url === null || ! self::isSafeHttpUrl($url)) {
            return null;
        }

        $isImageType = self::hasType($type, 'Image')
            || ($mime !== null && str_starts_with($mime, 'image/'))
            || (self::hasType($type, 'Document') && $mime !== null && str_starts_with($mime, 'image/'))
            || self::looksLikeImageUrl($url);

        if (! $isImageType) {
            return null;
        }

        if ($mime === null && self::looksLikeImageUrl($url)) {
            $mime = match (strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION))) {
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'jpg', 'jpeg' => 'image/jpeg',
                default => 'image/jpeg',
            };
        }

        return ['url' => $url, 'mime' => $mime, 'alt' => $alt];
    }

    private static function isSafeHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }

    private static function looksLikeImageUrl(string $url): bool
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));

        return (bool) preg_match('/\.(jpe?g|png|gif|webp|avif)(\?|$)/', $path);
    }
}
