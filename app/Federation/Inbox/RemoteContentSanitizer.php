<?php

namespace App\Federation\Inbox;

use App\Domain\Posts\PostBodyRenderer;

/**
 * Converte il campo "content" (HTML) di una Note remota in testo semplice
 * compatibile con la colonna "body" di post e commenti.
 *
 * Decisione architetturale: invece di introdurre un sanitizzatore HTML
 * completo (dipendenza aggiuntiva, superficie di rischio non banale per
 * contenuto arbitrario proveniente da server non fidati) il contenuto remoto
 * viene ridotto a testo semplice e fatto poi transitare dalla stessa
 * pipeline di rendering usata per i post locali ({@see PostBodyRenderer}),
 * che gia' esegue escaping HTML e linkificazione in modo sicuro.
 *
 * Prima di rimuovere i tag, i collegamenti HTML (&lt;a href&gt;) e quelli
 * Markdown `[etichetta](url)` vengono riscritti in forma plain-text che
 * {@see PostBodyRenderer} sa trasformare in link cliccabili, cosi' non si
 * perde l'URL dietro al testo del link.
 *
 * Gli hashtag (tipicamente `&lt;a class="hashtag" href="https://remoto/tags/…"&gt;#tag&lt;/a&gt;`)
 * vengono invece ridotti al solo testo `#tag`: cosi' il renderer li punta
 * alle pagine hashtag locali di Openbook, non al server remoto.
 *
 * Stessa idea per le menzioni: da `&lt;a class="mention" href="https://remoto/@user"&gt;@user&lt;/a&gt;`
 * a `@user@remoto` (o `@user` se il dominio e' quello dell'istanza), cosi'
 * {@see PostBodyRenderer} apre il profilo visto da Openbook.
 */
final class RemoteContentSanitizer
{
    public static function toPlainText(string $html): string
    {
        $withoutMedia = self::removeInlineVideos(self::removeInlineImages($html));
        $withLinks = self::preserveAnchors($withoutMedia);
        $withBreaks = preg_replace('#<br\s*/?>#i', "\n", $withLinks) ?? $withLinks;
        $withParagraphs = preg_replace('#</p>|</div>|</li>#i', "\n\n", $withBreaks) ?? $withBreaks;

        $text = html_entity_decode(strip_tags($withParagraphs), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Estrae le immagini inline (&lt;img src&gt;) dal content HTML remoto, da
     * unire agli attachment ActivityPub quando il client non invia "attachment".
     *
     * @return list<array{url: string, mime: string|null, alt: string|null}>
     */
    public static function extractInlineImages(string $html): array
    {
        if ($html === '') {
            return [];
        }

        $found = [];

        if (preg_match_all('#<img\b[^>]*>#is', $html, $tags) === false) {
            return [];
        }

        foreach ($tags[0] as $tag) {
            if (preg_match('#\bsrc\s*=\s*(["\'])(.*?)\1#is', $tag, $srcMatch) !== 1) {
                continue;
            }

            $url = html_entity_decode(trim($srcMatch[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (! self::isSafeHttpUrl($url)) {
                continue;
            }

            $alt = null;

            if (preg_match('#\balt\s*=\s*(["\'])(.*?)\1#is', $tag, $altMatch) === 1) {
                $alt = html_entity_decode(trim($altMatch[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $alt = mb_substr($alt, 0, 1000) ?: null;
            }

            $found[$url] = [
                'url' => $url,
                'mime' => self::guessImageMimeFromUrl($url),
                'alt' => $alt,
            ];
        }

        return array_values($found);
    }

    /**
     * Estrae i video inline (&lt;video&gt; / &lt;source&gt;) dal content HTML
     * remoto — tipico delle GIF convertite in MP4 su Mastodon.
     *
     * @return list<array{url: string, mime: string|null, alt: string|null}>
     */
    public static function extractInlineVideos(string $html): array
    {
        if ($html === '') {
            return [];
        }

        $found = [];

        if (preg_match_all('#<(?:video|source)\b[^>]*>#is', $html, $tags) !== false) {
            foreach ($tags[0] as $tag) {
                if (preg_match('#\bsrc\s*=\s*(["\'])(.*?)\1#is', $tag, $srcMatch) !== 1) {
                    continue;
                }

                $mime = null;

                if (preg_match('#\btype\s*=\s*(["\'])(.*?)\1#is', $tag, $typeMatch) === 1) {
                    $mime = strtolower(html_entity_decode(trim($typeMatch[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                }

                if ($mime !== null && ! str_starts_with($mime, 'video/')) {
                    continue;
                }

                $url = html_entity_decode(trim($srcMatch[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if (! self::isSafeHttpUrl($url)) {
                    continue;
                }

                $found[$url] = [
                    'url' => $url,
                    'mime' => $mime ?? self::guessVideoMimeFromUrl($url),
                    'alt' => null,
                ];
            }
        }

        return array_values($found);
    }

    /**
     * Rimuove i tag img cosi' il corpo non ripete l'URL in plain-text quando
     * l'immagine viene mostrata in galleria.
     */
    private static function removeInlineImages(string $html): string
    {
        return preg_replace('#<img\b[^>]*>#is', '', $html) ?? $html;
    }

    /**
     * Rimuove i player video inline (GIF-as-MP4 Mastodon) dal testo del post.
     */
    private static function removeInlineVideos(string $html): string
    {
        $html = preg_replace('#<video\b[^>]*>.*?</video>#is', '', $html) ?? $html;

        return preg_replace('#<source\b[^>]*>#is', '', $html) ?? $html;
    }

    private static function guessVideoMimeFromUrl(string $url): ?string
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));

        return match (true) {
            preg_match('/\.webm(\?|$)/', $path) === 1 => 'video/webm',
            preg_match('/\.(mp4|m4v|mov)(\?|$)/', $path) === 1 => 'video/mp4',
            default => null,
        };
    }

    private static function guessImageMimeFromUrl(string $url): ?string
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));

        return match (true) {
            preg_match('/\.png(\?|$)/', $path) === 1 => 'image/png',
            preg_match('/\.gif(\?|$)/', $path) === 1 => 'image/gif',
            preg_match('/\.webp(\?|$)/', $path) === 1 => 'image/webp',
            preg_match('/\.jpe?g(\?|$)/', $path) === 1 => 'image/jpeg',
            default => null,
        };
    }

    /**
     * Riscrive &lt;a href="https://..."&gt;etichetta&lt;/a&gt; in
     * `[etichetta](https://...)` (o solo l'URL se etichetta vuota/identica),
     * cosi' lo strip successivo non scarta l'href.
     */
    private static function preserveAnchors(string $html): string
    {
        return preg_replace_callback(
            '#<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
            static function (array $match): string {
                $url = html_entity_decode(trim($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $label = trim(html_entity_decode(strip_tags($match[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $label = preg_replace('/\s+/u', ' ', $label) ?? $label;

                $hashtag = self::extractHashtagLabel($label, $match[0], $url);

                if ($hashtag !== null) {
                    return $hashtag;
                }

                $mention = self::extractMentionLabel($label, $match[0], $url);

                if ($mention !== null) {
                    return $mention;
                }

                if (! self::isSafeHttpUrl($url)) {
                    return $label;
                }

                if ($label === '' || strcasecmp($label, $url) === 0) {
                    return $url;
                }

                // Evita di rompere la forma [etichetta](url) con parentesi
                // quadre dentro al testo del link.
                $label = str_replace(['[', ']'], ['(', ')'], $label);

                return '['.$label.']('.$url.')';
            },
            $html
        ) ?? $html;
    }

    /**
     * Riconosce i link hashtag tipici del fediverso e restituisce `#tag`
     * plain-text, oppure null se non e' un hashtag.
     */
    private static function extractHashtagLabel(string $label, string $anchorHtml, string $url): ?string
    {
        if (preg_match('/^#([\p{L}\p{N}_]{1,100})$/u', $label, $match) === 1) {
            return '#'.$match[1];
        }

        if (preg_match('/^([\p{L}\p{N}_]{1,100})$/u', $label, $match) !== 1) {
            return null;
        }

        $name = $match[1];
        $markedAsTag = preg_match('/\bhashtag\b/i', $anchorHtml) === 1
            || preg_match('/\brel\s*=\s*(["\'])[^"\']*\btag\b[^"\']*\1/i', $anchorHtml) === 1;
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $pathEndsWithTag = preg_match('#/(?:tags?|hashtags?)/'.preg_quote($name, '#').'/?$#iu', $path) === 1;

        if ($markedAsTag || $pathEndsWithTag) {
            return '#'.$name;
        }

        return null;
    }

    /**
     * Riconosce i link menzione tipici del fediverso e restituisce
     * `@user` / `@user@dominio` plain-text, oppure null.
     */
    private static function extractMentionLabel(string $label, string $anchorHtml, string $url): ?string
    {
        $username = null;
        $domain = null;

        if (preg_match('/^@([a-zA-Z0-9_]{1,32})(?:@([a-zA-Z0-9.\-]+))?$/u', $label, $match) === 1) {
            $username = $match[1];
            $domain = $match[2] ?? null;
        } else {
            $markedAsMention = preg_match('/\bmention\b/i', $anchorHtml) === 1
                || preg_match('/\bh-card\b/i', $anchorHtml) === 1;

            if (! $markedAsMention || preg_match('/^([a-zA-Z0-9_]{1,32})$/u', $label, $match) !== 1) {
                return null;
            }

            $username = $match[1];
        }

        if ($domain === null || $domain === '') {
            $host = parse_url($url, PHP_URL_HOST);
            $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

            if (is_string($host) && $host !== '' && preg_match('#/(?:users/|@)'.preg_quote($username, '#').'/?$#iu', $path) === 1) {
                $domain = $host;
            } elseif (is_string($host) && $host !== '' && preg_match('/\bmention\b/i', $anchorHtml) === 1) {
                $domain = $host;
            }
        }

        $localDomain = (string) config('openbook.domain');

        if ($domain === null || $domain === '' || strcasecmp($domain, $localDomain) === 0) {
            return '@'.$username;
        }

        return '@'.$username.'@'.mb_strtolower($domain);
    }

    private static function isSafeHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }
}
