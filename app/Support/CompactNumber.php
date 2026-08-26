<?php

namespace App\Support;

use Illuminate\Support\Facades\App;

/**
 * Conteggio compatto per le classifiche (hashtag in tendenza, ecc.):
 * sotto i 1000 resta il numero intero; da 1000 in su usa il suffisso "k"
 * (e "M" oltre il milione) con al piu' un decimale. Il separatore decimale
 * segue la lingua dell'interfaccia (virgola in italiano: 19,6k).
 */
final class CompactNumber
{
    public static function format(int $value): string
    {
        $absolute = abs($value);
        $sign = $value < 0 ? '-' : '';
        $decimal = str_starts_with(App::getLocale(), 'it') ? ',' : '.';

        if ($absolute < 1000) {
            return $sign.(string) $absolute;
        }

        if ($absolute < 1_000_000) {
            return $sign.self::scaled($absolute / 1000, $decimal).'k';
        }

        return $sign.self::scaled($absolute / 1_000_000, $decimal).'M';
    }

    private static function scaled(float $value, string $decimal): string
    {
        $rounded = round($value, 1);

        if (abs($rounded - round($rounded)) < 0.05) {
            return (string) (int) round($rounded);
        }

        return number_format($rounded, 1, $decimal, '');
    }
}
