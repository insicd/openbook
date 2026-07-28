<?php

namespace Tests\Support;

use App\Infrastructure\Security\Http\DnsResolver;

/**
 * Doppio di test per la risoluzione DNS: restituisce sempre un indirizzo IP
 * pubblico valido, cosi' che i test sulla federazione (che usano domini
 * fittizi come "remote.example") possano superare la protezione SSRF senza
 * dipendere da una rete reale o da domini realmente registrati. Le risposte
 * HTTP vere e proprie restano comunque simulate con Http::fake().
 */
final class FakeDnsResolver implements DnsResolver
{
    public function resolve(string $host): array
    {
        return ['1.1.1.1'];
    }
}
