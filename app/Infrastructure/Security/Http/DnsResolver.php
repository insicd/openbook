<?php

namespace App\Infrastructure\Security\Http;

/**
 * Astrae la risoluzione DNS di un host in una o piu' liste di indirizzi IP,
 * cosi' da poter sostituire l'implementazione reale con un doppio di test
 * senza dipendere da una rete o da domini realmente registrati.
 */
interface DnsResolver
{
    /**
     * @return list<string> indirizzi IPv4/IPv6 risolti per l'host, vuoto se
     *                      la risoluzione fallisce.
     */
    public function resolve(string $host): array;
}
