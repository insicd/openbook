<?php

namespace App\Infrastructure\Auth;

use App\Infrastructure\Database\SystemSetting;
use App\Infrastructure\Security\KeyPair;
use App\Infrastructure\Security\RsaKeyPairGenerator;
use JsonException;
use RuntimeException;

final class OAuthSigningKeys
{
    public const SETTING_KEY = 'oauth_signing_keys';

    private ?KeyPair $keys = null;

    public function __construct(private readonly RsaKeyPairGenerator $generator) {}

    public function getOrCreate(): KeyPair
    {
        if ($this->keys !== null) {
            return $this->keys;
        }
        $stored = SystemSetting::get(self::SETTING_KEY);
        if ($stored === null) {
            // A present but unreadable value must never silently rotate the signing pair.
            if (SystemSetting::query()->where('key', self::SETTING_KEY)->exists()) {
                throw new RuntimeException('The OAuth signing keys cannot be read.');
            }
            $generated = $this->generator->generate(4096);
            SystemSetting::putIfAbsent(self::SETTING_KEY, json_encode($generated, JSON_THROW_ON_ERROR));
            // Use the winner if another request initialized the pair first.
            $stored = SystemSetting::get(self::SETTING_KEY);
        }
        if ($stored === null) {
            throw new RuntimeException('Unable to initialize the OAuth signing keys.');
        }
        try {
            $pair = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('The OAuth signing keys are invalid.');
        }
        if (! is_array($pair) || ! is_string($pair['publicKey'] ?? null) || ! is_string($pair['privateKey'] ?? null)) {
            throw new RuntimeException('The OAuth signing keys are invalid.');
        }
        $private = openssl_pkey_get_private($pair['privateKey']);
        $public = openssl_pkey_get_public($pair['publicKey']);
        $privateDetails = $private === false ? false : openssl_pkey_get_details($private);
        $publicDetails = $public === false ? false : openssl_pkey_get_details($public);
        if ($privateDetails === false || $publicDetails === false
            || $privateDetails['type'] !== OPENSSL_KEYTYPE_RSA || $privateDetails['bits'] < 2048
            || ! hash_equals($privateDetails['key'], $publicDetails['key'])) {
            throw new RuntimeException('The OAuth signing keys are invalid.');
        }

        return $this->keys = new KeyPair(publicKey: $pair['publicKey'], privateKey: $pair['privateKey']);
    }
}
