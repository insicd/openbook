<?php

namespace App\Infrastructure\Security\Http;

/**
 * Impedisce che il server effettui richieste HTTP verso destinazioni non
 * affidabili (localhost, reti private, link-local, indirizzi riservati) per
 * conto di un URL fornito da un attore remoto. Ogni URL recuperato durante la
 * federazione (in questa fase: i documenti Actor per la verifica delle firme
 * HTTP) deve passare da qui prima di essere richiesto.
 */
final class SsrfGuard
{
    public function __construct(
        private readonly DnsResolver $resolver,
    ) {}

    /**
     * @throws SsrfViolationException
     */
    public function assertUrlIsSafe(string $url): ResolvedTarget
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'], $parts['scheme'])) {
            throw new SsrfViolationException("URL non valido: {$url}");
        }

        $scheme = mb_strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new SsrfViolationException("Schema non consentito: {$scheme}");
        }

        if ($scheme === 'http' && ! (bool) config('openbook.federation.fetch.allow_insecure')) {
            throw new SsrfViolationException('Sono consentite soltanto richieste HTTPS.');
        }

        $host = $parts['host'];
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $this->assertIpIsPublic($host);

            return new ResolvedTarget($host, $port, $host);
        }

        $addresses = $this->resolver->resolve($host);

        if ($addresses === []) {
            throw new SsrfViolationException("Impossibile risolvere l'host: {$host}");
        }

        foreach ($addresses as $address) {
            $this->assertIpIsPublic($address);
        }

        return new ResolvedTarget($host, $port, $addresses[0]);
    }

    private function assertIpIsPublic(string $ip): void
    {
        $isPublic = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

        if ($isPublic === false) {
            throw new SsrfViolationException("Indirizzo IP non consentito: {$ip}");
        }
    }
}
