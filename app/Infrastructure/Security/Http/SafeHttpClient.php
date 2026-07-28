<?php

namespace App\Infrastructure\Security\Http;

use Illuminate\Support\Facades\Http;

/**
 * Client HTTP per il recupero di risorse remote non affidabili (documenti
 * Actor). Ogni destinazione, incluse le ridirezioni, viene validata da
 * {@see SsrfGuard} e la connessione viene fissata sull'IP gia' verificato
 * tramite CURLOPT_RESOLVE, per evitare un DNS rebinding tra il controllo e la
 * richiesta effettiva.
 */
final class SafeHttpClient
{
    public function __construct(
        private readonly SsrfGuard $guard,
    ) {}

    /**
     * @param  array<string, string>  $headers
     *
     * @throws SsrfViolationException
     */
    public function get(string $url, array $headers = []): SafeHttpResponse
    {
        return $this->send('GET', $url, null, $headers);
    }

    /**
     * Usato per la consegna delle attivita' in uscita (Fase 4): il corpo va
     * passato gia' serializzato (non un array), perche' la firma HTTP deve
     * essere calcolata sugli stessi byte esatti inviati sul filo.
     *
     * @param  array<string, string>  $headers
     *
     * @throws SsrfViolationException
     */
    public function post(string $url, string $body, array $headers = []): SafeHttpResponse
    {
        return $this->send('POST', $url, $body, $headers);
    }

    /**
     * @param  array<string, string>  $headers
     *
     * @throws SsrfViolationException
     */
    private function send(string $method, string $url, ?string $body, array $headers): SafeHttpResponse
    {
        $maxRedirects = (int) config('openbook.federation.fetch.max_redirects', 3);
        $timeout = (int) config('openbook.federation.fetch.timeout_seconds', 10);
        $connectTimeout = (int) config('openbook.federation.fetch.connect_timeout_seconds', 5);
        $maxBytes = (int) config('openbook.federation.fetch.max_response_bytes', 1_000_000);

        $currentUrl = $url;

        for ($redirect = 0; $redirect <= $maxRedirects; $redirect++) {
            $target = $this->guard->assertUrlIsSafe($currentUrl);

            $request = Http::withHeaders(array_merge([
                'User-Agent' => (string) config('openbook.federation.user_agent'),
            ], $headers))
                ->withOptions([
                    'timeout' => $timeout,
                    'connect_timeout' => $connectTimeout,
                    'allow_redirects' => false,
                    'curl' => [
                        CURLOPT_RESOLVE => ["{$target->host}:{$target->port}:{$target->ip}"],
                    ],
                ]);

            $response = $method === 'POST'
                ? $request->withBody((string) $body, 'application/activity+json')->post($currentUrl)
                : $request->get($currentUrl);

            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                $location = $response->header('Location');

                if (blank($location)) {
                    throw new SsrfViolationException('Ridirezione priva di intestazione Location.');
                }

                // Una POST non deve mai essere ripetuta silenziosamente su un
                // altro URL con lo stesso corpo firmato per l'originale: la
                // firma HTTP e' calcolata su host/target specifici.
                if ($method === 'POST') {
                    throw new SsrfViolationException('Le richieste di consegna non seguono le ridirezioni.');
                }

                $currentUrl = $this->resolveRedirectLocation($currentUrl, $location);

                continue;
            }

            $responseBody = $response->body();

            if (strlen($responseBody) > $maxBytes) {
                throw new SsrfViolationException('Risposta remota troppo grande.');
            }

            return new SafeHttpResponse($response->status(), $response->headers(), $responseBody);
        }

        throw new SsrfViolationException('Numero massimo di ridirezioni superato.');
    }

    private function resolveRedirectLocation(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $baseParts = parse_url($base);
        $scheme = $baseParts['scheme'] ?? 'https';
        $host = $baseParts['host'] ?? '';
        $port = isset($baseParts['port']) ? ':'.$baseParts['port'] : '';

        if (str_starts_with($location, '/')) {
            return "{$scheme}://{$host}{$port}{$location}";
        }

        $basePath = $baseParts['path'] ?? '/';
        $dir = rtrim(dirname($basePath), '/');

        return "{$scheme}://{$host}{$port}{$dir}/{$location}";
    }
}
