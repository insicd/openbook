<?php

namespace App\Domain\Posts;

use Illuminate\Support\HtmlString;

/**
 * Trasforma il testo grezzo (sempre semplice, mai HTML) di un post o
 * commento in markup sicuro da mostrare nell'interfaccia: l'intero
 * contenuto viene prima sfuggito con {@see e()}, poi vengono aggiunti in
 * modo mirato i soli tag consentiti (link su URL/hashtag/menzioni, a-capo).
 *
 * Il lookbehind degli hashtag esclude "#" subito dopo "&" o ";": altrimenti
 * l'entita' HTML &#039; (apostrofo prodotto da e()) veniva spezzata in un
 * falso hashtag "#039", visibile in UI e nel content ActivityPub.
 *
 * URL, link con etichetta `[testo](url)`, hashtag e menzioni sono
 * riconosciuti in un'unica passata con un solo pattern combinato: operare
 * in piu' passate separate sulla stessa stringa sarebbe pericoloso, perche'
 * un URL gia' trasformato in un tag <a> (es. contenente "#sezione" o
 * "/@nome") verrebbe ri-processato dai pattern successivi, corrompendo
 * l'attributo href gia' scritto. Una singola passata garantisce invece che
 * ogni porzione di testo originale venga considerata una sola volta.
 */
final class PostBodyRenderer
{
    private const LINK_PATTERN = '/(?P<mdlink>\[(?P<mdlabel>[^\]]+)\]\((?P<mdurl>https?:\/\/[^\s\)]+)\))'
        .'|(?P<url>https?:\/\/[^\s<]+)'
        .'|(?P<hashtag>(?<![\w\/&;])#[\p{L}\p{N}_]{1,100})'
        .'|(?P<mention>(?<![\w])@[a-zA-Z0-9_]{1,32}(?:@[a-zA-Z0-9.\-]+)?)/u';

    /**
     * Punteggiatura finale che, se presente subito dopo un URL individuato
     * nel testo, quasi certamente appartiene alla frase e non all'indirizzo
     * (es. "vedi https://esempio.it." a fine periodo).
     */
    private const URL_TRAILING_PUNCTUATION = '.,;:!?';

    public static function render(string $body): HtmlString
    {
        $escaped = e($body);

        $rendered = preg_replace_callback(self::LINK_PATTERN, function (array $match) {
            if (($match['mdlink'] ?? '') !== '') {
                return self::renderLabeledUrl($match['mdurl'], $match['mdlabel']);
            }

            if (($match['url'] ?? '') !== '') {
                return self::renderUrl($match['url']);
            }

            if (($match['hashtag'] ?? '') !== '') {
                return self::renderHashtag($match['hashtag']);
            }

            return self::renderMention($match['mention']);
        }, $escaped);

        return new HtmlString(nl2br($rendered, false));
    }

    /**
     * $url e' gia' stato sfuggito da e() insieme al resto del testo: puo'
     * quindi essere riutilizzato cosi' com'e' nell'attributo href, senza
     * ri-escaping (che produrrebbe una doppia codifica) e senza rischio che
     * contenga virgolette o "<" letterali (gia' trasformati in entita').
     */
    private static function renderUrl(string $url): string
    {
        [$url, $trailing] = self::splitTrailingPunctuation($url);

        if ($url === '') {
            return $trailing;
        }

        return self::anchor($url, $url).$trailing;
    }

    /**
     * Link con etichetta in forma Markdown leggera `[etichetta](https://...)`,
     * usata anche per i collegamenti HTML dei post remoti dopo
     * {@see \App\Federation\Inbox\RemoteContentSanitizer}. Etichetta e URL
     * sono gia' sfuggiti da e().
     */
    private static function renderLabeledUrl(string $url, string $label): string
    {
        [$url, $trailing] = self::splitTrailingPunctuation($url);

        if ($url === '') {
            return $label.$trailing;
        }

        return self::anchor($url, $label).$trailing;
    }

    private static function anchor(string $href, string $text): string
    {
        return sprintf(
            '<a href="%s" class="post-link" target="_blank" rel="noopener noreferrer nofollow ugc">%s</a>',
            $href,
            $text
        );
    }

    /**
     * @return array{0: string, 1: string} l'URL ripulito e la punteggiatura
     *                                     finale scorporata (da riattaccare fuori dal link)
     */
    private static function splitTrailingPunctuation(string $url): array
    {
        $trailing = '';

        while ($url !== '' && str_contains(self::URL_TRAILING_PUNCTUATION, substr($url, -1))) {
            $trailing = substr($url, -1).$trailing;
            $url = substr($url, 0, -1);
        }

        // Una parentesi/chiusura finale resta parte del link solo se l'URL
        // contiene anche l'apertura corrispondente (es. wikipedia "(disambigua)").
        foreach ([')' => '(', ']' => '[', '}' => '{'] as $close => $open) {
            while ($url !== '' && substr($url, -1) === $close && substr_count($url, $open) < substr_count($url, $close)) {
                $trailing = $close.$trailing;
                $url = substr($url, 0, -1);
            }
        }

        return [$url, $trailing];
    }

    /**
     * $hashtag e $mention contengono solo caratteri che htmlspecialchars()
     * non tocca mai (lettere, cifre, "_", "." e "-"): possono quindi essere
     * riusati cosi' come sono, senza bisogno di un secondo escaping.
     */
    private static function renderHashtag(string $hashtag): string
    {
        $name = substr($hashtag, 1);
        $tag = Hashtag::normalize($name);

        return sprintf('<a href="%s" class="hashtag">#%s</a>', e(route('hashtags.show', $tag)), $name);
    }

    private static function renderMention(string $mention): string
    {
        $handle = substr($mention, 1);
        [$username] = explode('@', $handle, 2);

        return sprintf('<a href="%s" class="mention">@%s</a>', e(url('/@'.$username)), $handle);
    }
}
