<?php

namespace App\Domain\Posts;

use App\Federation\Actors\Actor;
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
 *
 * Hashtag e menzioni provenienti da post remoti (anche gia' salvati come
 * `[#tag](https://remoto/…)` / `[@user](https://remoto/…)`) vengono
 * ripuntati alle pagine locali di Openbook quando possibile.
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

    /**
     * Cache per-request delle URL profilo risolte, per evitare N query uguali
     * quando lo stesso handle compare in piu' post del feed.
     *
     * @var array<string, string>
     */
    private static array $mentionHrefCache = [];

    public static function clearMentionHrefCache(): void
    {
        self::$mentionHrefCache = [];
    }

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

        // Sostituire i newline (non nl2br): nl2br lascia i \n dopo <br>, e
        // Mastodon li interpreta di nuovo → a capo duplicati in federazione.
        return new HtmlString(str_replace(["\r\n", "\r", "\n"], '<br>', $rendered));
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
     *
     * Se l'etichetta e' un hashtag o una menzione (tipici dei post remoti
     * gia' in cache), si renderizza verso le pagine locali di Openbook.
     */
    private static function renderLabeledUrl(string $url, string $label): string
    {
        if (preg_match('/^#[\p{L}\p{N}_]{1,100}$/u', $label) === 1) {
            return self::renderHashtag($label);
        }

        if (preg_match('/^@[a-zA-Z0-9_]{1,32}(?:@[a-zA-Z0-9.\-]+)?$/u', $label) === 1) {
            return self::renderMention($label, $url);
        }

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

    private static function renderMention(string $mention, ?string $href = null): string
    {
        $handle = substr($mention, 1);
        $displayHandle = self::federatedMentionHandle($handle, $href);
        $profileHref = self::resolveMentionHref($handle, $href);

        return sprintf('<a href="%s" class="mention">@%s</a>', e($profileHref), e($displayHandle));
    }

    private static function resolveMentionHref(string $handle, ?string $href = null): string
    {
        $cacheKey = $handle.'|'.($href ?? '');

        if (isset(self::$mentionHrefCache[$cacheKey])) {
            return self::$mentionHrefCache[$cacheKey];
        }

        $actor = self::findMentionedActor($handle, $href);

        if ($actor !== null) {
            return self::$mentionHrefCache[$cacheKey] = $actor->profileUrl();
        }

        // Non in cache: ricerca con handle federato completo (user@dominio),
        // cosi' SearchController fa WebFinger e apre il profilo in Openbook.
        $searchQuery = self::federatedMentionHandle($handle, $href);

        return self::$mentionHrefCache[$cacheKey] = route('search.create', ['q' => $searchQuery]);
    }

    /**
     * Handle da usare in UI e in ricerca: se manca il dominio ma l'href
     * remoto lo espone, completa come `user@host`.
     */
    private static function federatedMentionHandle(string $handle, ?string $href = null): string
    {
        if (str_contains($handle, '@')) {
            return $handle;
        }

        if (! is_string($href) || $href === '') {
            return $handle;
        }

        $decodedHref = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $host = parse_url($decodedHref, PHP_URL_HOST);
        $path = (string) (parse_url($decodedHref, PHP_URL_PATH) ?? '');
        $username = $handle;

        if (preg_match('#/(?:users/|@)([a-zA-Z0-9_]{1,32})/?$#u', $path, $pathMatch) === 1) {
            $username = mb_strtolower($pathMatch[1]);
        }

        if (! is_string($host) || $host === '') {
            return $handle;
        }

        $host = mb_strtolower($host);
        $localDomain = mb_strtolower((string) config('openbook.domain'));

        if ($host === $localDomain) {
            return $username;
        }

        return $username.'@'.$host;
    }

    private static function findMentionedActor(string $handle, ?string $href = null): ?Actor
    {
        if (is_string($href) && $href !== '') {
            $decodedHref = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $byUri = Actor::query()
                ->where('uri', $decodedHref)
                ->where('status', Actor::STATUS_ACTIVE)
                ->first();

            if ($byUri !== null) {
                return $byUri;
            }
        }

        if (preg_match('/^([a-zA-Z0-9_]{1,32})(?:@([a-zA-Z0-9.\-]+))?$/u', $handle, $match) !== 1) {
            return null;
        }

        $username = mb_strtolower($match[1]);
        $domain = isset($match[2]) && $match[2] !== '' ? mb_strtolower($match[2]) : null;
        $localDomain = mb_strtolower((string) config('openbook.domain'));

        if ($domain === null || $domain === $localDomain) {
            $local = Actor::query()
                ->where('is_local', true)
                ->where('preferred_username', $username)
                ->where('status', Actor::STATUS_ACTIVE)
                ->first();

            if ($local !== null) {
                return $local;
            }
        }

        if ($domain !== null && $domain !== $localDomain) {
            return Actor::query()
                ->where('is_local', false)
                ->where('preferred_username', $username)
                ->where('domain', $domain)
                ->where('status', Actor::STATUS_ACTIVE)
                ->first();
        }

        if ($href !== null) {
            $decodedHref = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $host = parse_url($decodedHref, PHP_URL_HOST);
            $path = (string) (parse_url($decodedHref, PHP_URL_PATH) ?? '');

            if (is_string($host) && $host !== ''
                && preg_match('#/(?:users/|@)([a-zA-Z0-9_]{1,32})/?$#u', $path, $pathMatch) === 1
            ) {
                return Actor::query()
                    ->where('preferred_username', mb_strtolower($pathMatch[1]))
                    ->where('domain', mb_strtolower($host))
                    ->where('status', Actor::STATUS_ACTIVE)
                    ->first();
            }
        }

        return null;
    }
}
