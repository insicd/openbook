<?php

namespace App\Http\Support;

/**
 * Riconosce indirizzi federati "utente@dominio" (con o senza acct:/@/URL profilo)
 * nei campi di ricerca, per non confonderli con keyword libere.
 */
final class FederatedHandleParser
{
    /**
     * @return array{0: string, 1: string}|null [username, domain]
     */
    public static function parse(string $query): ?array
    {
        $query = ltrim(trim($query), '@');

        if (str_starts_with($query, 'acct:')) {
            $query = substr($query, 5);
        }

        if (preg_match('#^https?://#i', $query) === 1) {
            $host = parse_url($query, PHP_URL_HOST);
            $path = trim((string) parse_url($query, PHP_URL_PATH), '/');
            $segments = explode('/', $path);
            $lastSegment = ltrim((string) end($segments), '@');

            if (! is_string($host) || $host === '' || $lastSegment === '') {
                return null;
            }

            return [$lastSegment, $host];
        }

        if (preg_match('/\s/', $query) === 1) {
            return null;
        }

        $parts = explode('@', $query, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        return [$parts[0], $parts[1]];
    }
}
