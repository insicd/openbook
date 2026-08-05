<?php

namespace App\Domain\Posts;

use App\Federation\Actors\Actor;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use Illuminate\Support\HtmlString;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\UrlAutolinkParser;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\DisallowedRawHtml\DisallowedRawHtmlExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Trasforma il testo grezzo di un post o commento in HTML sicuro: Markdown
 * ampio (GFM), poi post-processing per hashtag, menzioni e attributi dei
 * link. L'HTML grezzo in ingresso viene rimosso (`html_input=strip`); le
 * immagini Markdown vengono scartate (gli allegati restano il canale
 * dedicato). L'autolink email e' disabilitato: altrimenti `@user@domain`
 * diventerebbe un `mailto:` spezzando le menzioni federate.
 *
 * Hashtag e menzioni provenienti da post remoti (anche gia' salvati come
 * `[#tag](https://remoto/…)` / `[@user](https://remoto/…)`) vengono
 * ripuntati alle pagine locali di Openbook quando possibile.
 *
 * Per l'HTML federato ({@see renderForFederation()}) le menzioni usano
 * invece l'identificatore ActivityPub canonico dell'attore (URI remoto o
 * `/users/…` locale), cosi' Mastodon e altri client aprono il profilo
 * sull'istanza di destinazione e non sulla cache `/attori/…` di Openbook.
 */
final class PostBodyRenderer
{
    private const INLINE_PATTERN = '/(?P<hashtag>(?<![\w\/&;])#[\p{L}\p{N}_]{1,100})'
        .'|(?P<mention>(?<![\w])@[a-zA-Z0-9_]{1,32}(?:@[a-zA-Z0-9.\-]+)?)/u';

    private static ?MarkdownConverter $converter = null;

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
        return self::renderBody($body, forFederation: false);
    }

    /**
     * Come {@see render()}, ma gli href delle menzioni puntano agli id
     * ActivityPub (non alle pagine HTML locali di Openbook).
     */
    public static function renderForFederation(string $body): HtmlString
    {
        return self::renderBody($body, forFederation: true);
    }

    private static function renderBody(string $body, bool $forFederation): HtmlString
    {
        if (trim($body) === '') {
            return new HtmlString('');
        }

        $html = trim((string) self::converter()->convert($body));

        if ($html === '') {
            return new HtmlString('');
        }

        return new HtmlString(self::enhanceRenderedHtml($html, $forFederation));
    }

    private static function converter(): MarkdownConverter
    {
        if (self::$converter instanceof MarkdownConverter) {
            return self::$converter;
        }

        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'renderer' => [
                // Mantiene il comportamento storico dei singoli a-capo nei
                // post "plain text", senza impedire paragrafi/liste Markdown.
                'soft_break' => '<br>',
            ],
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new DisallowedRawHtmlExtension);
        $environment->addExtension(new StrikethroughExtension);
        $environment->addExtension(new TableExtension);
        $environment->addExtension(new TaskListExtension);

        // Solo URL http(s): niente EmailAutolinkParser (rompe @user@domain).
        $environment->addInlineParser(new UrlAutolinkParser(['http', 'https'], 'https'));

        return self::$converter = new MarkdownConverter($environment);
    }

    private static function enhanceRenderedHtml(string $html, bool $forFederation = false): string
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="ob-md-root">'.$html.'</div>',
            LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('ob-md-root');

        if (! $root instanceof DOMElement) {
            return $html;
        }

        self::stripImages($root);
        self::processAnchors($root, $forFederation);
        self::linkifyTextNodes($root, $forFederation);

        $result = '';

        foreach ($root->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }

        return $result;
    }

    private static function stripImages(DOMElement $root): void
    {
        $xpath = new DOMXPath($root->ownerDocument);
        $images = [];

        foreach ($xpath->query('.//img', $root) ?: [] as $image) {
            $images[] = $image;
        }

        foreach ($images as $image) {
            $image->parentNode?->removeChild($image);
        }
    }

    private static function processAnchors(DOMElement $root, bool $forFederation = false): void
    {
        $xpath = new DOMXPath($root->ownerDocument);
        $anchors = [];

        foreach ($xpath->query('.//a', $root) ?: [] as $anchor) {
            if ($anchor instanceof DOMElement) {
                $anchors[] = $anchor;
            }
        }

        foreach ($anchors as $anchor) {
            $href = trim($anchor->getAttribute('href'));
            $label = $anchor->textContent ?? '';

            if (! self::isSafeHref($href)) {
                self::replaceNodeWithText($anchor, $label);

                continue;
            }

            if (preg_match('/^#[\p{L}\p{N}_]{1,100}$/u', $label) === 1) {
                self::replaceNode($anchor, self::createHashtagElement($root->ownerDocument, $label));

                continue;
            }

            if (preg_match('/^@[a-zA-Z0-9_]{1,32}(?:@[a-zA-Z0-9.\-]+)?$/u', $label) === 1) {
                self::replaceNode($anchor, self::createMentionElement($root->ownerDocument, $label, $href, $forFederation));

                continue;
            }

            $anchor->setAttribute('href', $href);

            if (preg_match('/^https?:\/\//i', $href) === 1) {
                $anchor->setAttribute('class', 'post-link');
                $anchor->setAttribute('target', '_blank');
                $anchor->setAttribute('rel', 'noopener noreferrer nofollow ugc');
            } else {
                $anchor->removeAttribute('target');
                $anchor->setAttribute('rel', 'noopener noreferrer nofollow ugc');
                $anchor->setAttribute('class', 'post-link');
            }
        }
    }

    private static function linkifyTextNodes(DOMElement $root, bool $forFederation = false): void
    {
        $xpath = new DOMXPath($root->ownerDocument);
        $textNodes = [];

        foreach ($xpath->query('.//text()[not(ancestor::a) and not(ancestor::code) and not(ancestor::pre)]', $root) ?: [] as $textNode) {
            if ($textNode instanceof DOMText) {
                $textNodes[] = $textNode;
            }
        }

        foreach ($textNodes as $textNode) {
            self::linkifyTextNode($textNode, $forFederation);
        }
    }

    private static function linkifyTextNode(DOMText $textNode, bool $forFederation = false): void
    {
        $text = $textNode->wholeText;

        if ($text === '' || (! str_contains($text, '#') && ! str_contains($text, '@'))) {
            return;
        }

        if (preg_match_all(self::INLINE_PATTERN, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === 0) {
            return;
        }

        $document = $textNode->ownerDocument;
        $parent = $textNode->parentNode;

        if ($document === null || $parent === null) {
            return;
        }

        $cursor = 0;

        foreach ($matches as $match) {
            $full = $match[0][0];
            $offset = (int) $match[0][1];

            if ($offset > $cursor) {
                $parent->insertBefore(
                    $document->createTextNode(substr($text, $cursor, $offset - $cursor)),
                    $textNode
                );
            }

            $hashtag = $match['hashtag'][0] ?? '';
            $mention = $match['mention'][0] ?? '';

            if ($hashtag !== '') {
                $parent->insertBefore(self::createHashtagElement($document, $hashtag), $textNode);
            } elseif ($mention !== '') {
                $parent->insertBefore(self::createMentionElement($document, $mention, null, $forFederation), $textNode);
            }

            $cursor = $offset + strlen($full);
        }

        if ($cursor < strlen($text)) {
            $parent->insertBefore(
                $document->createTextNode(substr($text, $cursor)),
                $textNode
            );
        }

        $parent->removeChild($textNode);
    }

    private static function isSafeHref(string $href): bool
    {
        if ($href === '') {
            return false;
        }

        if (str_starts_with($href, '/')) {
            return ! str_starts_with($href, '//');
        }

        return preg_match('/^(https?:\/\/|mailto:)/i', $href) === 1;
    }

    private static function createHashtagElement(DOMDocument $document, string $hashtag): DOMElement
    {
        $name = substr($hashtag, 1);
        $tag = Hashtag::normalize($name);

        $anchor = $document->createElement('a');
        $anchor->setAttribute('href', route('hashtags.show', $tag));
        $anchor->setAttribute('class', 'hashtag');
        $anchor->appendChild($document->createTextNode('#'.$name));

        return $anchor;
    }

    private static function createMentionElement(
        DOMDocument $document,
        string $mention,
        ?string $href = null,
        bool $forFederation = false,
    ): DOMElement {
        $handle = substr($mention, 1);
        $displayHandle = self::federatedMentionHandle($handle, $href);
        $profileHref = self::resolveMentionHref($handle, $href, $forFederation);

        $anchor = $document->createElement('a');
        $anchor->setAttribute('href', $profileHref);
        $anchor->setAttribute('class', 'mention');
        $anchor->appendChild($document->createTextNode('@'.$displayHandle));

        return $anchor;
    }

    private static function replaceNode(DOMNode $old, DOMNode $new): void
    {
        $old->parentNode?->replaceChild($new, $old);
    }

    private static function replaceNodeWithText(DOMElement $node, string $text): void
    {
        $document = $node->ownerDocument;

        if ($document === null) {
            return;
        }

        self::replaceNode($node, $document->createTextNode($text));
    }

    private static function resolveMentionHref(string $handle, ?string $href = null, bool $forFederation = false): string
    {
        $cacheKey = ($forFederation ? 'f:' : 'l:').$handle.'|'.($href ?? '');

        if (isset(self::$mentionHrefCache[$cacheKey])) {
            return self::$mentionHrefCache[$cacheKey];
        }

        $actor = self::findMentionedActor($handle, $href);

        if ($actor !== null) {
            // UI: pagina locale Openbook. Federazione: id ActivityPub
            // (URI remoto o /users/… locale) cosi' i client remoti non
            // aprono la cache /attori/… dell'istanza che ha pubblicato.
            return self::$mentionHrefCache[$cacheKey] = $forFederation
                ? $actor->activityPubId()
                : $actor->profileUrl();
        }

        $searchQuery = self::federatedMentionHandle($handle, $href);

        if ($forFederation) {
            // Preferisci l'href originale del link remoto, altrimenti un
            // profilo pubblico convenzionale sul dominio della menzione.
            if (is_string($href) && $href !== '') {
                $decodedHref = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if (self::isSafeHref($decodedHref) && preg_match('/^https?:\/\//i', $decodedHref) === 1) {
                    return self::$mentionHrefCache[$cacheKey] = $decodedHref;
                }
            }

            if (str_contains($searchQuery, '@')) {
                [$username, $domain] = explode('@', $searchQuery, 2);

                return self::$mentionHrefCache[$cacheKey] = 'https://'.$domain.'/@'.$username;
            }
        }

        // Non in cache (UI): ricerca con handle federato completo
        // (user@dominio), cosi' SearchController fa WebFinger.
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
