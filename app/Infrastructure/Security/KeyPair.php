<?php

namespace App\Infrastructure\Security;

/**
 * Value object immutabile per una coppia di chiavi RSA in formato PEM.
 */
final readonly class KeyPair
{
    public function __construct(
        public string $publicKey,
        public string $privateKey,
    ) {}
}
