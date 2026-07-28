<?php

namespace App\Infrastructure\Security;

use App\Federation\Actors\Actor;

/**
 * Esito della verifica di una firma HTTP in ingresso: se valida, espone
 * l'Actor firmatario (locale alla cache degli attori remoti, gia' con la
 * chiave pubblica caricata); se non valida, un messaggio pensato per i log
 * interni (mai per essere restituito cosi' com'e' al chiamante remoto).
 */
final readonly class SignatureVerificationResult
{
    private function __construct(
        public bool $valid,
        public ?Actor $actor,
        public ?string $keyId,
        public ?string $error,
    ) {}

    public static function success(Actor $actor, string $keyId): self
    {
        return new self(true, $actor, $keyId, null);
    }

    public static function failure(string $error, ?string $keyId = null): self
    {
        return new self(false, null, $keyId, $error);
    }
}
