<?php

namespace App\Support;

use Illuminate\Support\Facades\App;

/**
 * Conteggio compatto per le classifiche (hashtag in tendenza, ecc.):
 * sotto i 1000 resta il numero intero; da 1000 in su usa il suffisso "k"
 * (e "M" oltre il milione) con al piu' un decimale. Il separatore decimale
 * segue la lingua dell'interfaccia (virgola in italiano e finlandese: 19,6k).
 */
final class CompactNumber
{
    public static function format(int $value): string
    {
        $absolute = abs($value);

        if ($absolute < 1000) {
            return (string) $value;
        }

        $sign = $value < 0 ? '-' : '';
        $language = strtolower(explode('-', str_replace('_', '-', App::getLocale()))[0]);
        $decimal = in_array($language, ['it', 'fi'], true) ? ',' : '.';

        if ($absolute < 1_000_000) {
            return $sign.self::formatScaledValue($absolute / 1000, $decimal).'k';
        }

        return $sign.self::formatScaledValue($absolute / 1_000_000, $decimal).'M';
    }

    private static function formatScaledValue(float $value, string $decimal): string
    {
        $rounded = round($value, 1);

        if ($rounded === floor($rounded)) {
            return (string) (int) $rounded;
        }

        return number_format($rounded, 1, $decimal, '');
    }
}
