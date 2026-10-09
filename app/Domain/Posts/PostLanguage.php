<?php

namespace App\Domain\Posts;

/** Validazione sintattica BCP 47, senza lookup del registro o analisi del testo. */
final class PostLanguage
{
    public const MAX_LENGTH = 255;

    private const GRANDFATHERED = [
        'art-lojban', 'cel-gaulish', 'en-gb-oed', 'i-ami', 'i-bnn', 'i-default',
        'i-enochian', 'i-hak', 'i-klingon', 'i-lux', 'i-mingo', 'i-navajo', 'i-pwn',
        'i-tao', 'i-tay', 'i-tsu', 'no-bok', 'no-nyn', 'sgn-be-fr', 'sgn-be-nl',
        'sgn-ch-de', 'zh-guoyu', 'zh-hakka', 'zh-min', 'zh-min-nan', 'zh-xiang',
    ];

    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > self::MAX_LENGTH) {
            return null;
        }

        $tag = strtolower($value);
        $primary = explode('-', $tag, 2)[0];

        if ($tag === 'i-default' || in_array($primary, ['und', 'mul', 'zxx', 'unknown', 'x'], true)) {
            return null;
        }

        if (in_array($tag, self::GRANDFATHERED, true)) {
            return $tag;
        }

        // RFC 5646 §2.1: language, extlang, script, region, variants,
        // extensions e private-use. I soli private-use non identificano una lingua.
        $pattern = '~\A(?:[a-z]{2,3}(?:-[a-z]{3}){0,3}|[a-z]{4}|[a-z]{5,8})'
            .'(?:-[a-z]{4})?(?:-(?:[a-z]{2}|[0-9]{3}))?'
            .'(?<variants>(?:-(?:[a-z0-9]{5,8}|[0-9][a-z0-9]{3}))*)'
            .'(?<extensions>(?:-[0-9a-wy-z](?:-[a-z0-9]{2,8})+)*)'
            .'(?:-x(?:-[a-z0-9]{1,8})+)?\z~D';

        if (preg_match($pattern, $tag, $parts) !== 1) {
            return null;
        }

        $variants = array_filter(explode('-', $parts['variants']));
        preg_match_all('/-([0-9a-wy-z])(?=-)/', $parts['extensions'], $extensions);

        // Varianti e singleton ripetuti non sono well-formed (§2.2.9).
        if (count($variants) !== count(array_unique($variants))
            || count($extensions[1]) !== count(array_unique($extensions[1]))) {
            return null;
        }

        return $tag;
    }
}
