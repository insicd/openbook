<?php

namespace App\Infrastructure\Security;

use RuntimeException;

/**
 * Genera coppie di chiavi RSA in formato PEM per la firma HTTP delle
 * attivita' ActivityPub (RFC 9421 / Cavage draft, a seconda del software
 * remoto). Usa esclusivamente l'estensione OpenSSL nativa di PHP: nessuna
 * dipendenza esterna e nessun processo permanente, per restare compatibile
 * con hosting condivisi.
 */
final class RsaKeyPairGenerator
{
    public function __construct(
        private readonly int $defaultBits = 2048,
    ) {}

    public function generate(?int $bits = null): KeyPair
    {
        $config = [
            'private_key_bits' => $bits ?? $this->defaultBits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $resource = openssl_pkey_new($config);

        if ($resource === false) {
            throw new RuntimeException('Impossibile generare la coppia di chiavi RSA: '.$this->lastOpenSslError());
        }

        if (openssl_pkey_export($resource, $privateKeyPem, null, $config) === false) {
            throw new RuntimeException('Impossibile esportare la chiave privata RSA: '.$this->lastOpenSslError());
        }

        $details = openssl_pkey_get_details($resource);

        if ($details === false || ! isset($details['key'])) {
            throw new RuntimeException('Impossibile derivare la chiave pubblica RSA.');
        }

        return new KeyPair(publicKey: $details['key'], privateKey: $privateKeyPem);
    }

    private function lastOpenSslError(): string
    {
        $message = openssl_error_string();

        return $message !== false ? $message : 'errore OpenSSL sconosciuto';
    }
}
