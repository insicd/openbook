<?php

namespace App\Infrastructure\Security;

use InvalidArgumentException;
use RuntimeException;

/**
 * Firma HTTP secondo lo schema "Cavage" (draft-cavage-http-signatures),
 * l'unico effettivamente interoperabile con il resto del Fediverso oggi
 * (Mastodon, Pleroma, Akkoma, ecc. non implementano ancora la piu' recente
 * RFC 9421). Usa esclusivamente l'estensione OpenSSL nativa di PHP.
 *
 * In questa fase il servizio e' pronto per firmare richieste in uscita (sara'
 * usato dalla consegna delle attivita' nella Fase 4); qui viene gia'
 * riutilizzato per costruire/verificare la stessa "stringa da firmare" sia in
 * fase di firma sia in fase di verifica (vedi {@see HttpSignatureVerifier}).
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
