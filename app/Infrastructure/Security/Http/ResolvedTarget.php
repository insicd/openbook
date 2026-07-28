<?php

namespace App\Infrastructure\Security\Http;

/**
 * Destinazione di rete gia' validata come sicura: host e porta originali (per
 * gli header HTTP, es. "Host") e l'indirizzo IP verso cui instradare
 * effettivamente la connessione, fissato con CURLOPT_RESOLVE per evitare un
 * DNS rebinding tra il controllo e la richiesta reale.
 */
final readonly class ResolvedTarget
{
    public function __construct(
        public string $host,
        public int $port,
        public string $ip,
    ) {}
}
