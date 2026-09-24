<?php

namespace App\Domain\Posts;

final class PostBodyUrlFinder
{
    /** @return list<string> */
    public static function previewCandidates(string $body): array
    {
        // Links to tagged actors and hashtags are references, not articles.
        $body = preg_replace('/\[[#@][^\]\r\n]+\]\(https?:\/\/[^\s)]+\)/iu', '', $body) ?? $body;

        return self::distinct($body);
    }

    /** @return list<string> */
    public static function distinct(string $body): array
    {
        preg_match_all('/https?:\/\/[^\s<]+/iu', $body, $matches);
        $urls = [];

        foreach ($matches[0] ?? [] as $rawUrl) {
            $url = self::normalize($rawUrl);

            if ($url !== null) {
                $urls[$url] = $url;
            }
        }

        return array_values($urls);
    }

    private static function normalize(string $url): ?string
    {
        $url = rtrim($url, '.,;:!?');

        foreach ([')' => '(', ']' => '[', '}' => '{'] as $close => $open) {
            while (str_ends_with($url, $close) && substr_count($url, $open) < substr_count($url, $close)) {
                $url = substr($url, 0, -1);
            }
        }

        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $url;
    }
}
