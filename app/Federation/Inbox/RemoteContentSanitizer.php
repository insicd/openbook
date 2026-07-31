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
 */
final class RemoteContentSanitizer
{
    public static function toPlainText(string $html): string
    {
        $withLinks = self::preserveAnchors($html);
        $withBreaks = preg_replace('#<br\s*/?>#i', "\n", $withLinks) ?? $withLinks;
        $withParagraphs = preg_replace('#</p>|</div>|</li>#i', "\n\n", $withBreaks) ?? $withBreaks;

        $text = html_entity_decode(strip_tags($withParagraphs), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
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

    private static function isSafeHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }
}
