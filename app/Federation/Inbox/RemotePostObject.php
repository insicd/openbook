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

        if (is_string($name)) {
            $name = trim($name);

            if ($name !== '') {
                return mb_substr($name, 0, 255);
            }
        }

        // Fallback per Note Openbook precedenti a name: titolo solo nel
        // content come <p><b>…</b></p> (il markdown **grassetto** usa
        // <strong>, quindi non viene scambiato per un titolo).
        return self::titleFromBoldContentPrefix($document);
    }

    /**
     * Flag esplicito Mastodon/litepub ({@code directMessage: true}) su Note DM.
     *
     * @param  array<string, mixed>  $document
     */
    public static function isExplicitDirectMessage(array $document): bool
    {
        $value = $document['directMessage'] ?? false;

        if ($value === true || $value === 1) {
            return true;
        }

        if (is_string($value) && strtolower(trim($value)) === 'true') {
            return true;
        }

        return false;
    }

    /**
     * URI del messaggio a cui si risponde (Mastodon: {@code inReplyTo} /
     * {@code inReplyToAtomUri} / ostatus).
     *
     * @param  array<string, mixed>  $document
     */
    public static function inReplyToTarget(array $document): ?string
    {
        foreach (['inReplyTo', 'inReplyToAtomUri'] as $field) {
            $value = $document[$field] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Conteggio {@code totalItems} di una collection ActivityPub (likes,
     * shares): presente quando il server manda l'oggetto inline sulla Note.
     * Una stringa URL non ha il totale: va dereferenziata a parte.
     */
    public static function collectionTotalItems(mixed $collection): ?int
    {
        if (! is_array($collection)) {
            return null;
        }

        $value = $collection['totalItems'] ?? null;

        if (is_array($value) && array_key_exists('@value', $value)) {
            $value = $value['@value'];
        }

        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && is_numeric($value) && (int) $value >= 0) {
            return (int) $value;
        }

        return null;
    }

    /**
     * URL della collection se il campo e' una stringa o un oggetto con id.
     */
    public static function collectionUrl(mixed $collection): ?string
    {
        if (is_string($collection) && $collection !== '') {
            return $collection;
        }

        if (is_array($collection) && is_string($collection['id'] ?? null) && $collection['id'] !== '') {
            return $collection['id'];
        }

        return null;
    }

    /**
     * True se la Note ha testo in content, contentMap o source.
     *
     * @param  array<string, mixed>  $document
     */
    public static function hasRawContent(array $document): bool
    {
        return self::rawContent($document) !== '';
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
            $title = self::title($document);

            if ($title !== null) {
                $body = self::stripTitlePrefixFromBody($body, $title);
            }

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
     * Allegati media da mostrare in galleria (immagini, GIF, video MP4 loop
     * stile Mastodon). Non scarica i file: solo URL https.
     *
     * @param  array<string, mixed>  $document
     * @return list<array{url: string, mime: string|null, alt: string|null}>
     */
    public static function mediaAttachments(array $document): array
    {
        $found = [];

        $attachments = $document['attachment'] ?? null;

        if (is_array($attachments)) {
            if (isset($attachments['type']) || isset($attachments['url']) || isset($attachments['href'])) {
                $attachments = [$attachments];
            }

            foreach ($attachments as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $descriptor = self::videoDescriptorFromObject($item)
                    ?? self::audioDescriptorFromObject($item)
                    ?? self::imageDescriptorFromObject($item);

                if ($descriptor !== null) {
                    $found[$descriptor['url']] = $descriptor;
                }
            }
        }

        if (self::hasType($document['type'] ?? null, 'Image')) {
            $descriptor = self::imageDescriptorFromObject($document);

            if ($descriptor !== null) {
                $found[$descriptor['url']] = $descriptor;
            }
        }

        if (self::hasType($document['type'] ?? null, 'Video')) {
            $descriptor = self::videoDescriptorFromObject($document);

            if ($descriptor !== null) {
                $found[$descriptor['url']] = $descriptor;
            }
        }

        if (self::hasType($document['type'] ?? null, 'Audio')) {
            $descriptor = self::audioDescriptorFromObject($document);

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

        $contentHtml = self::rawContent($document);

        foreach (RemoteContentSanitizer::extractInlineImages($contentHtml) as $descriptor) {
            $found[$descriptor['url']] = $descriptor;
        }

        foreach (RemoteContentSanitizer::extractInlineVideos($contentHtml) as $descriptor) {
            $found[$descriptor['url']] = $descriptor;
        }

        return self::deduplicateMediaAttachments(array_values($found));
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<array{url: string, mime: string|null, alt: string|null}>
     */
    public static function imageAttachments(array $document): array
    {
        return array_values(array_filter(
            self::mediaAttachments($document),
            static fn (array $descriptor): bool => ! str_starts_with((string) ($descriptor['mime'] ?? ''), 'video/')
                && ! str_starts_with((string) ($descriptor['mime'] ?? ''), 'audio/'),
        ));
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
     * Titolo federato da Openbook nel content HTML: primo paragrafo che e'
     * interamente &lt;b&gt;…&lt;/b&gt; (non &lt;strong&gt;: quello e' markdown).
     *
     * @param  array<string, mixed>  $document
     */
    private static function titleFromBoldContentPrefix(array $document): ?string
    {
        $html = self::rawContent($document);

        if ($html === '') {
            return null;
        }

        if (preg_match('/^\s*<p>\s*<b>(.*?)<\/b>\s*<\/p>/is', $html, $match) !== 1) {
            return null;
        }

        $title = trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $title === '' ? null : mb_substr($title, 0, 255);
    }

    private static function stripTitlePrefixFromBody(string $body, string $title): string
    {
        $title = trim($title);

        if ($title === '' || ! str_starts_with($body, $title)) {
            return $body;
        }

        $rest = mb_substr($body, mb_strlen($title));

        if ($rest !== '' && preg_match('/^\s/u', $rest) !== 1) {
            return $body;
        }

        return ltrim($rest);
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
    private static function videoDescriptorFromObject(array $object): ?array
    {
        $type = $object['type'] ?? null;
        $mime = is_string($object['mediaType'] ?? null) ? strtolower($object['mediaType']) : null;
        $alt = null;

        if (is_string($object['name'] ?? null)) {
            $alt = mb_substr(trim($object['name']), 0, 1000) ?: null;
        }

        $url = self::extractUrl($object['url'] ?? null, preferHtml: false)
            ?? (is_string($object['href'] ?? null) ? $object['href'] : null);

        if ($url === null && is_string($object['id'] ?? null) && self::looksLikeVideoUrl($object['id'])) {
            $url = $object['id'];
        }

        if ($url === null || ! self::isSafeHttpUrl($url)) {
            return null;
        }

        $isVideoType = self::hasType($type, 'Video')
            || ($mime !== null && str_starts_with($mime, 'video/'))
            || (self::hasType($type, 'Document') && $mime !== null && str_starts_with($mime, 'video/'))
            || self::looksLikeVideoUrl($url);

        if (! $isVideoType) {
            return null;
        }

        if ($mime === null) {
            $mime = match (strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION))) {
                'webm' => 'video/webm',
                'mp4', 'm4v', 'mov' => 'video/mp4',
                default => 'video/mp4',
            };
        }

        return ['url' => $url, 'mime' => $mime, 'alt' => $alt];
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array{url: string, mime: string|null, alt: string|null}|null
     */
    private static function audioDescriptorFromObject(array $object): ?array
    {
        $type = $object['type'] ?? null;
        $mime = is_string($object['mediaType'] ?? null) ? strtolower($object['mediaType']) : null;
        $alt = null;

        if (is_string($object['name'] ?? null)) {
            $alt = mb_substr(trim($object['name']), 0, 1000) ?: null;
        }

        $url = self::extractUrl($object['url'] ?? null, preferHtml: false)
            ?? (is_string($object['href'] ?? null) ? $object['href'] : null);

        if ($url === null && is_string($object['id'] ?? null) && self::looksLikeAudioUrl($object['id'])) {
            $url = $object['id'];
        }

        if ($url === null || ! self::isSafeHttpUrl($url)) {
            return null;
        }

        $isAudioType = self::hasType($type, 'Audio')
            || ($mime !== null && str_starts_with($mime, 'audio/'))
            || (self::hasType($type, 'Document') && $mime !== null && str_starts_with($mime, 'audio/'))
            || self::looksLikeAudioUrl($url);

        if (! $isAudioType) {
            return null;
        }

        if ($mime === null) {
            $mime = match (strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION))) {
                'mp3' => 'audio/mpeg',
                'ogg' => 'audio/ogg',
                'wav' => 'audio/wav',
                'm4a' => 'audio/mp4',
                'flac' => 'audio/flac',
                'aac' => 'audio/aac',
                'webm' => 'audio/webm',
                default => 'audio/mpeg',
            };
        }

        return ['url' => $url, 'mime' => $mime, 'alt' => $alt];
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

    private static function looksLikeVideoUrl(string $url): bool
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));

        return (bool) preg_match('/\.(mp4|webm|m4v|mov)(\?|$)/', $path);
    }

    private static function looksLikeAudioUrl(string $url): bool
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));

        return (bool) preg_match('/\.(mp3|ogg|wav|m4a|flac|aac)(\?|$)/', $path);
    }

    /**
     * Alcuni server (WordPress ActivityPub, Mastodon inline + attachment)
     * inviano piu' URL per la stessa immagine (thumbnail + media/full).
     * Raggruppa per identita' del file e conserva la variante piu' grande.
     *
     * @param  list<array{url: string, mime: string|null, alt: string|null}>  $attachments
     * @return list<array{url: string, mime: string|null, alt: string|null}>
     */
    private static function deduplicateMediaAttachments(array $attachments): array
    {
        if (count($attachments) <= 1) {
            return $attachments;
        }

        $images = [];
        $others = [];

        foreach ($attachments as $descriptor) {
            $mime = (string) ($descriptor['mime'] ?? '');

            if (str_starts_with($mime, 'video/') || str_starts_with($mime, 'audio/')) {
                $others[] = $descriptor;

                continue;
            }

            $images[self::mediaAttachmentGroupKey($descriptor['url'])][] = $descriptor;
        }

        $deduped = [];

        foreach ($images as $group) {
            $deduped[] = self::preferBestImageDescriptor($group);
        }

        return array_values(array_merge($deduped, $others));
    }

    /**
     * Chiave stabile per varianti della stessa immagine (WordPress -WxH,
     * -scaled, Mastodon original/small, ecc.).
     */
    private static function mediaAttachmentGroupKey(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = strtolower((string) ($parts['path'] ?? ''));

        $path = preg_replace('#/(original|small|thumbnail|preview)/#', '/_/', $path) ?? $path;
        $path = preg_replace('#-\d+x\d+(?=\.[a-z0-9]+$)#', '', $path) ?? $path;
        $path = preg_replace('#-scaled(?=\.[a-z0-9]+$)#', '', $path) ?? $path;

        return $host.'|'.$path;
    }

    /**
     * @param  list<array{url: string, mime: string|null, alt: string|null}>  $group
     * @return array{url: string, mime: string|null, alt: string|null}
     */
    private static function preferBestImageDescriptor(array $group): array
    {
        usort(
            $group,
            static fn (array $left, array $right): int => self::imageVariantScore($right['url']) <=> self::imageVariantScore($left['url']),
        );

        $best = $group[0];

        if (($best['alt'] ?? null) === null || trim((string) $best['alt']) === '') {
            foreach ($group as $candidate) {
                $alt = $candidate['alt'] ?? null;

                if (is_string($alt) && trim($alt) !== '') {
                    $best['alt'] = $alt;

                    break;
                }
            }
        }

        return $best;
    }

    /**
     * Punteggio piu' alto = variante preferita (originale o risoluzione maggiore).
     */
    private static function imageVariantScore(string $url): int
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        $filename = basename($path);

        if (preg_match('#/(original)/#', $path)) {
            return PHP_INT_MAX - 1;
        }

        if (preg_match('#/(small|thumbnail|preview)/#', $path)) {
            return 100;
        }

        if (! preg_match('#-\d+x\d+(?=\.[a-z0-9]+$)#i', $filename)
            && ! preg_match('#-scaled(?=\.[a-z0-9]+$)#i', $filename)) {
            return PHP_INT_MAX;
        }

        if (preg_match('#-scaled(?=\.[a-z0-9]+$)#i', $filename)) {
            return PHP_INT_MAX - 2;
        }

        if (preg_match('#-(\d+)x(\d+)(?=\.[a-z0-9]+$)#i', $filename, $matches)) {
            return (int) $matches[1] * (int) $matches[2];
        }

        return 1000;
    }
}
