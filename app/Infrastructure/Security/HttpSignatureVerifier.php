<?php

namespace App\Infrastructure\Security;

use App\Federation\Actors\RemoteActorResolver;
use Illuminate\Http\Request;

/**
 * Verifica le firme HTTP delle richieste in ingresso (inbox), secondo lo
 * schema descritto in {@see HttpSignatureSigner}. Controlla, nell'ordine
 * richiesto dal design: presenza e formato dell'header Signature, validita'
 * temporale di Date, corrispondenza del Digest, e infine la firma
 * crittografica rispetto alla chiave pubblica dell'Actor dichiarato da
 * keyId — con un solo tentativo di aggiornamento della chiave in caso di
 * fallimento, per gestire la rotazione delle chiavi senza cicli infiniti.
 */
final class HttpSignatureVerifier
{
    public function __construct(
        private readonly RemoteActorResolver $remoteActorResolver,
    ) {}

    public function verify(Request $request): SignatureVerificationResult
    {
        $header = $request->header('Signature');

        if (blank($header)) {
            return SignatureVerificationResult::failure('Intestazione Signature mancante.');
        }

        $parsed = $this->parseSignatureHeader($header);

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

            if (blank($providedDigest) || ! hash_equals($expectedDigest, $providedDigest)) {
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

        $signingString = $this->buildSigningStringFromRequest($request, $parsed['headers']);

        $actor = $this->remoteActorResolver->resolveByKeyId($keyId);

        if ($actor === null || $actor->key === null || blank($actor->key->public_key)) {
            return SignatureVerificationResult::failure("Impossibile recuperare la chiave pubblica dell'attore firmatario.", $keyId);
        }

        if ($this->verifySignature($signingString, $signatureBinary, $actor->key->public_key)) {
            return SignatureVerificationResult::success($actor, $keyId);
        }

        // La chiave potrebbe essere stata ruotata sul server remoto: un solo
        // tentativo di aggiornamento, poi si abbandona (nessun ciclo).
        $refreshed = $this->remoteActorResolver->refresh($actor);

        if ($refreshed !== null
            && $refreshed->key !== null
            && filled($refreshed->key->public_key)
            && $this->verifySignature($signingString, $signatureBinary, $refreshed->key->public_key)) {
            return SignatureVerificationResult::success($refreshed, $keyId);
        }

        return SignatureVerificationResult::failure('Firma crittografica non valida.', $keyId);
    }

    private function verifySignature(string $signingString, string $signatureBinary, string $publicKeyPem): bool
    {
        return openssl_verify($signingString, $signatureBinary, $publicKeyPem, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * @return array{keyId: string, headers: list<string>, signature: string}|null
     */
    private function parseSignatureHeader(string $header): ?array
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
    private function buildSigningStringFromRequest(Request $request, array $signedHeaders): string
    {
        $headers = [];

        foreach ($signedHeaders as $name) {
            $lower = mb_strtolower($name);

            if ($lower === '(request-target)') {
                continue;
            }

            $headers[$lower] = (string) $request->header($name, '');
        }

        return HttpSignatureSigner::buildSigningString($request->method(), $request->getRequestUri(), $headers, $signedHeaders);
    }
}
