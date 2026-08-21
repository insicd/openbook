<?php

namespace App\Support;

/**
 * Numerazione Openbook da 26.34 in poi: {@code YY.settimana} per una
 * stable (es. {@code 26.34}), poi {@code YY.settimana.rcN} per le patch
 * candidate successive (es. {@code 26.34.rc1}).
 *
 * Le 0.x restano le pre-stable; una CalVer e' sempre piu' nuova di una 0.x.
 * Attenzione: PHP {@see version_compare()} tratterebbe {@code .rc} come
 * pre-release *prima* della stable; qui .rcN viene *dopo* la stable della
 * stessa settimana.
 */
final class OpenbookVersion
{
    /**
     * Stable {@code 26.34}, patch RC {@code 26.34.rc1}, oppure legacy
     * {@code 0.8.11} / {@code 0.9.2}.
     */
    public static function isValid(string $version): bool
    {
        return self::parse($version) !== null;
    }

    public static function isNewer(string $candidate, string $current): bool
    {
        $left = self::parse($candidate);
        $right = self::parse($current);

        if ($left === null || $right === null) {
            return false;
        }

        return ($left <=> $right) === 1;
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int}|null
     *         [epoca, a, b, c] con epoca 1 = CalVer, 0 = legacy 0.x
     */
    private static function parse(string $version): ?array
    {
        $version = trim($version);

        if (preg_match('/^(\d{2})\.(\d{1,2})(?:\.rc(\d+))?$/', $version, $matches) === 1
            && (int) $matches[1] >= 20) {
            return [
                1,
                (int) $matches[1],
                (int) $matches[2],
                isset($matches[3]) ? (int) $matches[3] : 0,
            ];
        }

        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $matches) === 1) {
            return [
                0,
                (int) $matches[1],
                (int) $matches[2],
                (int) $matches[3],
            ];
        }

        return null;
    }
}
