<?php

namespace App\Support;

/**
 * Riconoscimento minimale del client (senza librerie esterne) per adattare
 * l'interfaccia e i default di autenticazione sui telefoni.
 */
final class ClientDevice
{
    public static function isMobile(?string $userAgent = null): bool
    {
        $userAgent ??= (string) request()->userAgent();

        return (bool) preg_match(
            '/Mobile|Android|iP(hone|od)|IEMobile|BlackBerry|Opera Mini/i',
            $userAgent,
        );
    }
}
