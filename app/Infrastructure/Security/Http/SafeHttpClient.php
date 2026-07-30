<?php

namespace App\Infrastructure\Security\Http;

use App\Federation\Actors\Actor;
use App\Infrastructure\Security\HttpSignatureSigner;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Client HTTP per il recupero di risorse remote non affidabili (documenti
 * Actor, outbox, replies). Ogni destinazione, incluse le ridirezioni, viene
 * validata da {@see SsrfGuard} e la connessione viene fissata sull'IP gia'
 * verificato tramite CURLOPT_RESOLVE, per evitare un DNS rebinding tra il
 * controllo e la richiesta effettiva.
 *
 * I GET ActivityPub possono essere firmati (authorized fetch) con la chiave
 * di un Actor locale: in quel caso ogni hop di ridirezione viene ri-firmato
 * e, su 401 con query string, si ritenta la firma senza query (legacy Mastodon).
 */
final class SafeHttpClient
{
    public function __construct(
        private readonly SsrfGuard $guard,
        private readonly HttpSignatureSigner $signer,
    ) {}

    /**
     * @param  array<string, string>  $headers
     *
     * @throws SsrfViolationException
     */
    public function get(string $url, array $headers = [], ?Actor $signingActor = null): SafeHttpResponse
    {
        return $this->send('GET', $url, null, $headers, $signingActor);
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
        return $this->send('POST', $url, $body, $headers, null);
    }

    /**
     * @param  array<string, string>  $headers
     *
     * @throws SsrfViolationException
     */
    private function send(
        string $method,
        string $url,
        ?string $body,
        array $headers,
        ?Actor $signingActor,
    ): SafeHttpResponse {
        $maxRedirects = (int) config('openbook.federation.fetch.max_redirects', 3);
        $signGets = $method === 'GET'
            && $signingActor !== null
            && $signingActor->key !== null
            && $signingActor->key->hasPrivateKey()
            && (bool) config('openbook.federation.fetch.signed', true);

        $currentUrl = $url;

        for ($redirect = 0; $redirect <= $maxRedirects; $redirect++) {
            $target = $this->guard->assertUrlIsSafe($currentUrl);

            $requestHeaders = $headers;

            if ($signGets) {
                $requestHeaders = array_merge(
                    $requestHeaders,
                    $this->signer->authorizationHeaders('GET', $currentUrl, $signingActor),
                );
            }

            $response = $this->performRequest($method, $currentUrl, $body, $requestHeaders, $target);

            if ($signGets
                && $response->status() === 401
                && $this->urlHasQuery($currentUrl)
            ) {
                // Double-knock: alcuni server Mastodon verificano ancora
                // (request-target) senza query string.
                $retryHeaders = array_merge(
                    $headers,
                    $this->signer->authorizationHeaders(
                        'GET',
                        $currentUrl,
                        $signingActor,
                        omitQueryString: true,
                    ),
                );
                $response = $this->performRequest($method, $currentUrl, $body, $retryHeaders, $target);
            }

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
            $maxBytes = (int) config('openbook.federation.fetch.max_response_bytes', 1_000_000);

            if (strlen($responseBody) > $maxBytes) {
                throw new SsrfViolationException('Risposta remota troppo grande.');
            }

            return new SafeHttpResponse($response->status(), $response->headers(), $responseBody);
        }

        throw new SsrfViolationException('Numero massimo di ridirezioni superato.');
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function performRequest(
        string $method,
        string $url,
        ?string $body,
        array $headers,
        ResolvedTarget $target,
    ): Response {
        $timeout = (int) config('openbook.federation.fetch.timeout_seconds', 10);
        $connectTimeout = (int) config('openbook.federation.fetch.connect_timeout_seconds', 5);

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

        return $method === 'POST'
            ? $request->withBody((string) $body, 'application/activity+json')->post($url)
            : $request->get($url);
    }

    private function urlHasQuery(string $url): bool
    {
        $query = parse_url($url, PHP_URL_QUERY);

        return is_string($query) && $query !== '';
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
