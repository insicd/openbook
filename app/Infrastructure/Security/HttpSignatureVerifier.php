<?php

namespace App\Infrastructure\Security;

use App\Federation\Actors\RemoteActorResolver;
use Illuminate\Http\Request;

/**
 * Verifica le firme HTTP delle richieste in ingresso (inbox).
 *
 * Supporta:
 * - draft-cavage ({@code Signature: keyId="…",signature="…"}) — interoperabilita'
 *   Mastodon / Lemmy / maggioranza del Fediverso;
 * - RFC 9421 ({@code Signature-Input} + {@code Signature: sig1=:…:}) — usato
 *   in uscita da activitypub-bot / tags.pub sul primo tentativo ("double-knock").
 *
 * Controlla: formato firma, validita' temporale, Digest / Content-Digest, e
 * infine la firma crittografica rispetto alla chiave pubblica dell'Actor
 * dichiarato da keyId — con un solo tentativo di aggiornamento della chiave
 * in caso di fallimento.
 */
final class HttpSignatureVerifier
{
    public function __construct(
        private readonly RemoteActorResolver $remoteActorResolver,
    ) {}

    public function verify(Request $request): SignatureVerificationResult
    {
        $signatureHeader = $request->header('Signature');
        $signatureInput = $request->header('Signature-Input');

        if (filled($signatureInput) && filled($signatureHeader) && $this->isRfc9421SignatureHeader($signatureHeader)) {
            return $this->verifyRfc9421($request, $signatureInput, $signatureHeader);
        }

        return $this->verifyCavage($request, $signatureHeader);
    }

    private function verifyCavage(Request $request, ?string $header): SignatureVerificationResult
    {
        if (blank($header)) {
            return SignatureVerificationResult::failure('Intestazione Signature mancante.');
        }

        $parsed = $this->parseCavageSignatureHeader($header);

        if ($parsed === null) {
            return SignatureVerificationResult::failure('Intestazione Signature malformata.');
        }

        $keyId = $parsed['keyId'];

        $dateHeader = $request->header('Date');

        if (blank($dateHeader)) {
            return SignatureVerificationResult::failure('Intestazione Date mancante.', $keyId);
        }

        $timestamp = strtotime($dateHeader);
        $maxSkew = (int) config('openbook.federation.http_signature.max_clock_skew_seconds', 300);

        if ($timestamp === false || abs(time() - $timestamp) > $maxSkew) {
            return SignatureVerificationResult::failure("Intestazione Date fuori dall'intervallo accettabile.", $keyId);
        }

        $body = (string) $request->getContent();

        if ($body !== '') {
            $expectedDigest = HttpSignatureSigner::digest($body);
            $providedDigest = $request->header('Digest');

            if (blank($providedDigest) || ! $this->legacyDigestsMatch($expectedDigest, $providedDigest)) {
                return SignatureVerificationResult::failure('Digest del corpo non corrispondente.', $keyId);
            }
        }

        if ($parsed['signature'] === '') {
            return SignatureVerificationResult::failure('Firma vuota.', $keyId);
        }

        $signatureBinary = base64_decode($parsed['signature'], true);

        if ($signatureBinary === false) {
            return SignatureVerificationResult::failure('Firma non decodificabile.', $keyId);
        }

        $signingString = $this->buildCavageSigningStringFromRequest($request, $parsed['headers']);

        return $this->verifyWithRemoteKey($keyId, $signingString, $signatureBinary);
    }

    /**
     * RFC 9421 HTTP Message Signatures (sottoinsieme usato da activitypub-bot):
     * alg {@code rsa-v1_5-sha256}, componenti {@code @method}/{@code @target-uri}
     * e header {@code content-digest} sulle POST.
     */
    private function verifyRfc9421(Request $request, string $signatureInput, string $signatureHeader): SignatureVerificationResult
    {
        $input = $this->parseBestRfc9421Input($signatureInput);

        if ($input === null) {
            return SignatureVerificationResult::failure('Intestazione Signature-Input non supportata.');
        }

        $keyId = $input['keyid'] ?? null;

        if (! is_string($keyId) || $keyId === '') {
            return SignatureVerificationResult::failure('Signature-Input senza keyid.');
        }

        $created = $input['created'] ?? null;
        $maxSkew = (int) config('openbook.federation.http_signature.max_clock_skew_seconds', 300);

        if (! is_int($created) || abs(time() - $created) > $maxSkew) {
            return SignatureVerificationResult::failure("Parametro created fuori dall'intervallo accettabile.", $keyId);
        }

        $body = (string) $request->getContent();
        $params = $input['params'];

        if ($body !== '' || in_array('content-digest', $params, true)) {
            $provided = $request->header('Content-Digest');

            if (blank($provided) || ! $this->contentDigestsMatch($body, $provided)) {
                return SignatureVerificationResult::failure('Content-Digest del corpo non corrispondente.', $keyId);
            }
        }

        $signatureBinary = $this->extractRfc9421SignatureBytes($signatureHeader, $input['name']);

        if ($signatureBinary === null) {
            return SignatureVerificationResult::failure('Firma RFC 9421 non decodificabile.', $keyId);
        }

        $signingString = $this->buildRfc9421SigningString($request, $input);

        if ($signingString === null) {
            return SignatureVerificationResult::failure('Componenti Signature-Input non ricostruibili.', $keyId);
        }

        return $this->verifyWithRemoteKey($keyId, $signingString, $signatureBinary);
    }

    private function verifyWithRemoteKey(string $keyId, string $signingString, string $signatureBinary): SignatureVerificationResult
    {
        $actor = $this->remoteActorResolver->resolveByKeyId($keyId);

        if ($actor === null || $actor->key === null || blank($actor->key->public_key)) {
            return SignatureVerificationResult::failure("Impossibile recuperare la chiave pubblica dell'attore firmatario.", $keyId);
        }

        if ($this->verifyRsaSha256($signingString, $signatureBinary, $actor->key->public_key)) {
            return SignatureVerificationResult::success($actor, $keyId);
        }

        // La chiave potrebbe essere stata ruotata sul server remoto: un solo
        // tentativo di aggiornamento, poi si abbandona (nessun ciclo).
        $refreshed = $this->remoteActorResolver->refresh($actor);

        if ($refreshed !== null
            && $refreshed->key !== null
            && filled($refreshed->key->public_key)
            && $this->verifyRsaSha256($signingString, $signatureBinary, $refreshed->key->public_key)) {
            return SignatureVerificationResult::success($refreshed, $keyId);
        }

        return SignatureVerificationResult::failure('Firma crittografica non valida.', $keyId);
    }

    private function verifyRsaSha256(string $signingString, string $signatureBinary, string $publicKeyPem): bool
    {
        return openssl_verify($signingString, $signatureBinary, $publicKeyPem, OPENSSL_ALGO_SHA256) === 1;
    }

    private function isRfc9421SignatureHeader(string $header): bool
    {
        return (bool) preg_match('/\w+=:[A-Za-z0-9+\/=]*:/', $header);
    }

    /**
     * @return array{keyId: string, headers: list<string>, signature: string}|null
     */
    private function parseCavageSignatureHeader(string $header): ?array
    {
        $attributes = [];

        preg_match_all('/(\w+)="([^"]*)"/', $header, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $attributes[$match[1]] = $match[2];
        }

        if (! isset($attributes['keyId'], $attributes['signature']) || $attributes['keyId'] === '') {
            return null;
        }

        $headers = isset($attributes['headers']) && $attributes['headers'] !== ''
            ? explode(' ', $attributes['headers'])
            : ['date'];

        return [
            'keyId' => $attributes['keyId'],
            'headers' => $headers,
            'signature' => $attributes['signature'],
        ];
    }

    /**
     * @param  list<string>  $signedHeaders
     */
    private function buildCavageSigningStringFromRequest(Request $request, array $signedHeaders): string
    {
        $headers = [];

        foreach ($signedHeaders as $name) {
            $lower = mb_strtolower($name);

            if ($lower === '(request-target)') {
                continue;
            }

            // activitypub-bot firma i valori trimmati: allinea la verifica.
            $headers[$lower] = trim((string) $request->header($name, ''));
        }

        return HttpSignatureSigner::buildSigningString($request->method(), $request->getRequestUri(), $headers, $signedHeaders);
    }

    private function legacyDigestsMatch(string $expected, string $provided): bool
    {
        $expectedParts = explode('=', $expected, 2);
        $providedParts = explode('=', $provided, 2);

        if (count($expectedParts) !== 2 || count($providedParts) !== 2) {
            return false;
        }

        return strcasecmp($expectedParts[0], $providedParts[0]) === 0
            && hash_equals($expectedParts[1], $providedParts[1]);
    }

    private function contentDigestsMatch(string $body, string $provided): bool
    {
        $expectedHash = base64_encode(hash('sha256', $body, true));

        // RFC 9530 / activitypub-bot: sha-256=:BASE64:
        if (preg_match('/^sha-256=:(.*?):$/i', trim($provided), $matches) === 1) {
            return hash_equals($expectedHash, $matches[1]);
        }

        // Variante senza wrapping :…: (alcuni client)
        if (preg_match('/^sha-256=(.+)$/i', trim($provided), $matches) === 1) {
            return hash_equals($expectedHash, trim($matches[1], ':'));
        }

        return false;
    }

    /**
     * @return array{name: string, params: list<string>, attrStr: string, keyid?: string, alg?: string, created?: int}|null
     */
    private function parseBestRfc9421Input(string $signatureInput): ?array
    {
        $inputs = [];

        if (preg_match_all('/(\w+)=(\([^)]*\))((?:;[^,]*)*)/', $signatureInput, $matches, PREG_SET_ORDER) === 0) {
            return null;
        }

        foreach ($matches as $match) {
            $name = $match[1];
            $componentList = $match[2];
            $paramSuffix = $match[3];
            $attrStr = $componentList.$paramSuffix;

            $params = [];

            if (preg_match_all('/"([^"]+)"/', $componentList, $componentMatches) > 0) {
                $params = $componentMatches[1];
            }

            $values = ['name' => $name, 'params' => $params, 'attrStr' => $attrStr];

            if (preg_match_all('/;(\w+)=("(?:[^"\\\\]|\\\\.)*"|\\d+)/', $paramSuffix, $paramMatches, PREG_SET_ORDER) > 0) {
                foreach ($paramMatches as $paramMatch) {
                    $key = $paramMatch[1];
                    $raw = $paramMatch[2];
                    $values[$key] = ctype_digit($raw) ? (int) $raw : trim($raw, '"');
                }
            }

            $inputs[] = $values;
        }

        foreach (['rsa-v1_5-sha256'] as $alg) {
            foreach ($inputs as $input) {
                if (($input['alg'] ?? null) !== $alg) {
                    continue;
                }

                if (! in_array('@method', $input['params'], true)) {
                    continue;
                }

                if (! in_array('@target-uri', $input['params'], true)
                    && ! (in_array('@scheme', $input['params'], true)
                        && in_array('@authority', $input['params'], true)
                        && in_array('@path', $input['params'], true))) {
                    continue;
                }

                return $input;
            }
        }

        return null;
    }

    private function extractRfc9421SignatureBytes(string $signatureHeader, string $name): ?string
    {
        if (preg_match('/'.preg_quote($name, '/').'=:([^:]+):/', $signatureHeader, $matches) !== 1) {
            return null;
        }

        $binary = base64_decode($matches[1], true);

        return $binary === false ? null : $binary;
    }

    /**
     * @param  array{name: string, params: list<string>, attrStr: string, keyid?: string, alg?: string, created?: int}  $input
     */
    private function buildRfc9421SigningString(Request $request, array $input): ?string
    {
        $lines = [];
        $targetUri = $request->fullUrl();

        foreach ($input['params'] as $param) {
            $value = match ($param) {
                '@method' => strtoupper($request->method()),
                '@target-uri' => $targetUri,
                '@authority' => (string) $request->getHttpHost(),
                '@scheme' => $request->getScheme(),
                '@path' => '/'.ltrim($request->getPathInfo(), '/'),
                '@query' => $request->getQueryString() !== null && $request->getQueryString() !== ''
                    ? '?'.$request->getQueryString()
                    : '',
                '@request-target' => $request->getRequestUri(),
                default => null,
            };

            if ($value === null) {
                if (str_starts_with($param, '@')) {
                    return null;
                }

                $headerValue = $request->header($param);

                if ($headerValue === null) {
                    return null;
                }

                $value = is_array($headerValue) ? implode(', ', $headerValue) : (string) $headerValue;
            }

            $lines[] = '"'.$param.'": '.$value;
        }

        $lines[] = '"@signature-params": '.$input['attrStr'];

        return implode("\n", $lines);
    }
}
