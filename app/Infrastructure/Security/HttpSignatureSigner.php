<?php

namespace App\Infrastructure\Security;

use App\Federation\Actors\Actor;
use InvalidArgumentException;
use RuntimeException;

/**
 * Firma HTTP secondo lo schema "Cavage" (draft-cavage-http-signatures),
 * l'unico effettivamente interoperabile con il resto del Fediverso oggi
 * (Mastodon, Pleroma, Akkoma, ecc. non implementano ancora la piu' recente
 * RFC 9421). Usa esclusivamente l'estensione OpenSSL nativa di PHP.
 *
 * Usata sia per la consegna delle attivita' (POST firmate) sia per i
 * "signed fetch" / authorized fetch (GET firmati) verso risorse ActivityPub
 * remote. La stessa "stringa da firmare" e' condivisa con
 * {@see HttpSignatureVerifier}.
 */
final class HttpSignatureSigner
{
    /**
     * Digest del corpo della richiesta nel formato atteso dall'header HTTP
     * "Digest" (RFC 3230): "SHA-256=<base64>".
     */
    public static function digest(string $body): string
    {
        return 'SHA-256='.base64_encode(hash('sha256', $body, true));
    }

    /**
     * Costruisce gli header HTTP di autenticazione (Date, Signature e, se
     * c'e' un corpo, Digest) per una richiesta verso $url firmata con la
     * chiave privata dell'Actor locale.
     *
     * @param  bool  $omitQueryString  se true, (request-target) esclude la
     *                                 query string (compatibilita' legacy
     *                                 Mastodon); l'URL sulla rete resta intero
     * @return array<string, string>
     */
    public function authorizationHeaders(
        string $method,
        string $url,
        Actor $actor,
        ?string $body = null,
        bool $omitQueryString = false,
    ): array {
        if ($actor->key === null || ! $actor->key->hasPrivateKey()) {
            throw new RuntimeException(
                "Impossibile firmare la richiesta: chiave privata assente per l'Actor {$actor->id}."
            );
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $query = parse_url($url, PHP_URL_QUERY);
        $target = (! $omitQueryString && is_string($query) && $query !== '')
            ? "{$path}?{$query}"
            : $path;

        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $headersToSign = [
            'host' => $host,
            'date' => $date,
        ];
        $signedComponents = ['(request-target)', 'host', 'date'];
        $outgoing = ['Date' => $date];

        if ($body !== null) {
            $digest = self::digest($body);
            $headersToSign['digest'] = $digest;
            $signedComponents[] = 'digest';
            $outgoing['Digest'] = $digest;
        }

        $outgoing['Signature'] = $this->sign(
            $method,
            $target,
            $headersToSign,
            $actor->activityPubId().'#main-key',
            $actor->key->private_key,
            $signedComponents,
        );

        return $outgoing;
    }

    /**
     * @param  array<string, string>  $headers  valori (gia' pronti, senza il nome) degli header da includere nella firma, indicizzati per nome minuscolo
     * @param  list<string>  $signedHeaders  ordine dei componenti da firmare, es. ['(request-target)', 'host', 'date', 'digest']
     */
    public function sign(string $method, string $target, array $headers, string $keyId, string $privateKeyPem, array $signedHeaders): string
    {
        $signingString = self::buildSigningString($method, $target, $headers, $signedHeaders);

        $ok = openssl_sign($signingString, $signatureBinary, $privateKeyPem, OPENSSL_ALGO_SHA256);

        if (! $ok) {
            throw new RuntimeException('Impossibile firmare la richiesta HTTP: '.(openssl_error_string() ?: 'errore OpenSSL sconosciuto'));
        }

        return sprintf(
            'keyId="%s",algorithm="rsa-sha256",headers="%s",signature="%s"',
            $keyId,
            implode(' ', $signedHeaders),
            base64_encode($signatureBinary)
        );
    }

    /**
     * @param  array<string, string>  $headers
     * @param  list<string>  $signedHeaders
     */
    public static function buildSigningString(string $method, string $target, array $headers, array $signedHeaders): string
    {
        $lowerHeaders = [];

        foreach ($headers as $name => $value) {
            $lowerHeaders[mb_strtolower($name)] = $value;
        }

        $lines = [];

        foreach ($signedHeaders as $component) {
            $component = mb_strtolower($component);

            if ($component === '(request-target)') {
                $lines[] = '(request-target): '.mb_strtolower($method).' '.$target;

                continue;
            }

            if (! array_key_exists($component, $lowerHeaders)) {
                throw new InvalidArgumentException("Header mancante per la firma: {$component}");
            }

            $lines[] = $component.': '.$lowerHeaders[$component];
        }

        return implode("\n", $lines);
    }
}
