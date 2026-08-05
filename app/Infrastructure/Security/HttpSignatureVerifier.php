<?php

namespace App\Infrastructure\Security;

use App\Federation\Actors\RemoteActorResolver;
use Illuminate\Http\Request;

/**
 * Verifica le firme HTTP delle richieste in ingresso (inbox), supportando sia
 * il formato legacy ActivityPub/Cavage sia HTTP Message Signatures RFC 9421.
 *
 * Controlla formato e validita' temporale della firma, corrispondenza del
 * digest del corpo e firma crittografica rispetto alla chiave pubblica
 * dell'Actor dichiarato dal keyId. In caso di fallimento della verifica usa
 * un solo tentativo di aggiornamento della chiave, per gestire la rotazione
 * senza cicli infiniti.
 */
final class HttpSignatureVerifier
{
    public function __construct(
        private readonly RemoteActorResolver $remoteActorResolver,
    ) {}

    public function verify(Request $request): SignatureVerificationResult
    {
        $signatureHeader = $request->header('Signature');

        if (blank($signatureHeader)) {
            return SignatureVerificationResult::failure('Intestazione Signature mancante.');
        }

        $signatureInputHeader = $request->header('Signature-Input');

        if (filled($signatureInputHeader)) {
            return $this->verifyRfc9421($request, $signatureHeader, $signatureInputHeader);
        }

        return $this->verifyLegacy($request, $signatureHeader);
    }

    private function verifyLegacy(Request $request, string $header): SignatureVerificationResult
    {
        $parsed = $this->parseLegacySignatureHeader($header);

        if ($parsed === null) {
            return SignatureVerificationResult::failure('Intestazione Signature malformata.');
        }

        $keyId = $parsed['keyId'];
        $dateHeader = $request->header('Date');

        if (blank($dateHeader)) {
            return SignatureVerificationResult::failure('Intestazione Date mancante.', $keyId);
        }

        $timestamp = strtotime($dateHeader);

        if (! $this->timestampIsAcceptable($timestamp === false ? null : $timestamp)) {
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

        $signatureBinary = $this->decodeBase64Signature($parsed['signature']);

        if ($signatureBinary === null) {
            return SignatureVerificationResult::failure('Firma non decodificabile.', $keyId);
        }

        $signingString = $this->buildLegacySigningStringFromRequest($request, $parsed['headers']);

        return $this->verifyWithActorKey($keyId, $signingString, $signatureBinary);
    }

    private function verifyRfc9421(
        Request $request,
        string $signatureHeader,
        string $signatureInputHeader,
    ): SignatureVerificationResult {
        $parsed = $this->parseRfc9421Headers($signatureHeader, $signatureInputHeader);

        if ($parsed === null) {
            return SignatureVerificationResult::failure('Intestazioni Signature RFC 9421 malformate.');
        }

        $keyId = $parsed['keyId'];

        if (! $this->timestampIsAcceptable($parsed['created'])) {
            return SignatureVerificationResult::failure(
                "Parametro created della firma fuori dall'intervallo accettabile.",
                $keyId,
            );
        }

        if (in_array('content-digest', $parsed['components'], true)) {
            $contentDigest = $request->header('Content-Digest');

            if (blank($contentDigest)
                || ! $this->verifyContentDigest((string) $request->getContent(), $contentDigest)) {
                return SignatureVerificationResult::failure('Content-Digest del corpo non corrispondente.', $keyId);
            }
        }

        $signingString = $this->buildRfc9421SigningString(
            $request,
            $parsed['components'],
            $parsed['signatureParams'],
        );

        if ($signingString === null) {
            return SignatureVerificationResult::failure(
                'La firma RFC 9421 contiene componenti non supportati.',
                $keyId,
            );
        }

        return $this->verifyWithActorKey($keyId, $signingString, $parsed['signatureBinary']);
    }

    private function verifyWithActorKey(
        string $keyId,
        string $signingString,
        string $signatureBinary,
    ): SignatureVerificationResult {
        $actor = $this->remoteActorResolver->resolveByKeyId($keyId);

        if ($actor === null || $actor->key === null || blank($actor->key->public_key)) {
            return SignatureVerificationResult::failure(
                "Impossibile recuperare la chiave pubblica dell'attore firmatario.",
                $keyId,
            );
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

    private function timestampIsAcceptable(?int $timestamp): bool
    {
        if ($timestamp === null) {
            return false;
        }

        $maxSkew = (int) config('openbook.federation.http_signature.max_clock_skew_seconds', 300);

        return abs(time() - $timestamp) <= $maxSkew;
    }

    private function verifySignature(string $signingString, string $signatureBinary, string $publicKeyPem): bool
    {
        return openssl_verify($signingString, $signatureBinary, $publicKeyPem, OPENSSL_ALGO_SHA256) === 1;
    }

    private function decodeBase64Signature(string $signature): ?string
    {
        if ($signature === '') {
            return null;
        }

        $decoded = base64_decode($signature, true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * @return array{keyId: string, headers: list<string>, signature: string}|null
     */
    private function parseLegacySignatureHeader(string $header): ?array
    {
        $attributes = [];

        preg_match_all(
            '/([A-Za-z][A-Za-z0-9_-]*)="((?:\\\\.|[^"\\\\])*)"/',
            $header,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $attributes[$match[1]] = stripcslashes($match[2]);
        }

        if (! isset($attributes['keyId'], $attributes['signature']) || $attributes['keyId'] === '') {
            return null;
        }

        $headers = filled($attributes['headers'] ?? null)
            ? preg_split('/\s+/', trim($attributes['headers']), -1, PREG_SPLIT_NO_EMPTY)
            : ['date'];

        return [
            'keyId' => $attributes['keyId'],
            'headers' => $headers,
            'signature' => $attributes['signature'],
        ];
    }

    /**
     * Parser intenzionalmente ristretto al profilo RFC 9421 osservato:
     * componenti semplici tra virgolette e parametri created/keyid.
     *
     * @return array{
     *   keyId: string,
     *   created: int,
     *   components: list<string>,
     *   signatureParams: string,
     *   signatureBinary: string
     * }|null
     */
    private function parseRfc9421Headers(string $signatureHeader, string $signatureInputHeader): ?array
    {
        if (! preg_match(
            '/(?:^|,\s*)([A-Za-z][A-Za-z0-9_-]*)=:([A-Za-z0-9+\/=]+):(?:\s*,|$)/',
            $signatureHeader,
            $signatureMatch,
        )) {
            return null;
        }

        $label = $signatureMatch[1];
        $signatureBinary = $this->decodeBase64Signature($signatureMatch[2]);

        if ($signatureBinary === null) {
            return null;
        }

        $quotedLabel = preg_quote($label, '/');

        if (! preg_match(
            '/(?:^|,\s*)'.$quotedLabel.'=(\((?:"[^"]+"\s*)+\)(?:;[A-Za-z][A-Za-z0-9_-]*=(?:"(?:\\\\.|[^"\\\\])*"|[0-9]+))*)(?:\s*,|$)/',
            $signatureInputHeader,
            $inputMatch,
        )) {
            return null;
        }

        $signatureParams = $inputMatch[1];

        if (! preg_match('/^(\((.*?)\))(.*)$/', $signatureParams, $paramsMatch)) {
            return null;
        }

        preg_match_all('/"([^"]+)"/', $paramsMatch[2], $componentMatches);
        $components = array_values(array_map('strtolower', $componentMatches[1]));

        if ($components === []) {
            return null;
        }

        if (! preg_match('/;created=([0-9]+)/', $paramsMatch[3], $createdMatch)
            || ! preg_match('/;keyid="((?:\\\\.|[^"\\\\])*)"/', $paramsMatch[3], $keyIdMatch)) {
            return null;
        }

        $keyId = stripcslashes($keyIdMatch[1]);

        if ($keyId === '') {
            return null;
        }

        return [
            'keyId' => $keyId,
            'created' => (int) $createdMatch[1],
            'components' => $components,
            'signatureParams' => $signatureParams,
            'signatureBinary' => $signatureBinary,
        ];
    }

    private function verifyContentDigest(string $body, string $header): bool
    {
        if (! preg_match('/(?:^|,\s*)sha-256=:([A-Za-z0-9+\/=]+):(?:\s*,|$)/i', $header, $match)) {
            return false;
        }

        $provided = base64_decode($match[1], true);

        if ($provided === false) {
            return false;
        }

        $expected = hash('sha256', $body, true);

        return hash_equals($expected, $provided);
    }

    /**
     * @param  list<string>  $components
     */
    private function buildRfc9421SigningString(
        Request $request,
        array $components,
        string $signatureParams,
    ): ?string {
        $lines = [];

        foreach ($components as $component) {
            $value = match ($component) {
                '@method' => $request->method(),
                '@target-uri' => $request->getSchemeAndHttpHost().$request->getRequestUri(),
                'date' => $request->header('Date'),
                'user-agent' => $request->header('User-Agent'),
                'content-type' => $request->header('Content-Type'),
                'content-digest' => $request->header('Content-Digest'),
                default => null,
            };

            if ($value === null) {
                return null;
            }

            $lines[] = '"'.$component.'": '.$value;
        }

        $lines[] = '"@signature-params": '.$signatureParams;

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $signedHeaders
     */
    private function buildLegacySigningStringFromRequest(Request $request, array $signedHeaders): string
    {
        $headers = [];

        foreach ($signedHeaders as $name) {
            $lower = mb_strtolower($name);

            if ($lower === '(request-target)') {
                continue;
            }

            $headers[$lower] = (string) $request->header($name, '');
        }

        return HttpSignatureSigner::buildSigningString(
            $request->method(),
            $request->getRequestUri(),
            $headers,
            $signedHeaders,
        );
    }
}
