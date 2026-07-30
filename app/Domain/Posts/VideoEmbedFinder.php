<?php

namespace App\Domain\Posts;

/**
 * Individua nel testo grezzo di un post il *primo* link a un video
 * YouTube o PeerTube e ne ricava l'URL di embed da usare in un iframe.
 *
 * Se nel post ci sono piu' link video, solo il primo (in ordine di
 * apparizione nel testo) viene considerato: evita di riempire la card
 * di player multipli e tiene prevedibile il layout del feed.
 *
 * PeerTube non ha un dominio unico (ogni istanza ha il suo): viene
 * riconosciuto dalla forma del percorso (`/w/...`, `/videos/watch/...`,
 * `/videos/embed/...`), abbastanza tipica del software e rara altrove.
 * YouTube e' riconosciuto dai host noti (`youtube.com`, `youtu.be`, ecc.).
 */
final class VideoEmbedFinder
{
    private const URL_PATTERN = '/https?:\/\/[^\s<]+/u';

    private const URL_TRAILING_PUNCTUATION = '.,;:!?';

    /**
     * @var list<string>
     */
    private const YOUTUBE_HOSTS = [
        'youtube.com',
        'www.youtube.com',
        'm.youtube.com',
        'music.youtube.com',
        'youtu.be',
        'www.youtu.be',
        'youtube-nocookie.com',
        'www.youtube-nocookie.com',
    ];

    public static function first(string $body): ?VideoEmbed
    {
        if ($body === '') {
            return null;
        }

        if (preg_match_all(self::URL_PATTERN, $body, $matches) === false) {
            return null;
        }

        foreach ($matches[0] as $rawUrl) {
            $url = self::normalizeUrl($rawUrl);

            if ($url === null) {
                continue;
            }

            $embed = self::fromYoutube($url) ?? self::fromPeertube($url);

            if ($embed !== null) {
                return $embed;
            }
        }

        return null;
    }

    private static function normalizeUrl(string $rawUrl): ?string
    {
        [$url] = self::splitTrailingPunctuation($rawUrl);

        if ($url === '') {
            return null;
        }

        // html_entity_decode non serve sul body grezzo (mai HTML), ma
        // ripulisce eventuali &amp; arrivati da copie/incolla strani.
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        return $url;
    }

    private static function fromYoutube(string $url): ?VideoEmbed
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (! in_array($host, self::YOUTUBE_HOSTS, true)) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $videoId = null;

        if ($host === 'youtu.be' || $host === 'www.youtu.be') {
            $videoId = trim($path, '/');
        } elseif (preg_match('#^/(?:embed|shorts|live)/([A-Za-z0-9_-]{6,})#', $path, $match) === 1) {
            $videoId = $match[1];
        } elseif (isset($query['v']) && is_string($query['v'])) {
            $videoId = $query['v'];
        }

        if ($videoId === null || preg_match('/^[A-Za-z0-9_-]{6,}$/', $videoId) !== 1) {
            return null;
        }

        // youtube-nocookie riduce il tracciamento di terze parti rispetto
        // a youtube.com/embed, in linea con la postura privacy-first.
        return new VideoEmbed(
            VideoEmbed::PROVIDER_YOUTUBE,
            'https://www.youtube-nocookie.com/embed/'.$videoId,
            $url,
        );
    }

    private static function fromPeertube(string $url): ?VideoEmbed
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($host === '' || $path === '') {
            return null;
        }

        // Forme tipiche PeerTube. L'id puo' essere uno short-id oppure un UUID.
        if (preg_match('#^/(?:w|videos/watch|videos/embed)/([A-Za-z0-9_-]{8,})#', $path, $match) !== 1) {
            return null;
        }

        $videoId = $match[1];

        // Evita falsi positivi banali su siti generici con un path /w/corto.
        if (strlen($videoId) < 8) {
            return null;
        }

        return new VideoEmbed(
            VideoEmbed::PROVIDER_PEERTUBE,
            'https://'.$host.'/videos/embed/'.$videoId,
            $url,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitTrailingPunctuation(string $url): array
    {
        $trailing = '';

        while ($url !== '' && str_contains(self::URL_TRAILING_PUNCTUATION, substr($url, -1))) {
            $trailing = substr($url, -1).$trailing;
            $url = substr($url, 0, -1);
        }

        foreach ([')' => '(', ']' => '[', '}' => '{'] as $close => $open) {
            while ($url !== '' && substr($url, -1) === $close && substr_count($url, $open) < substr_count($url, $close)) {
                $trailing = $close.$trailing;
                $url = substr($url, 0, -1);
            }
        }

        return [$url, $trailing];
    }
}
