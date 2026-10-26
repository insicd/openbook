<?php

namespace Tests\Concerns;

trait UsesPassportKeys
{
    private static ?array $passportKeys = null;

    private function configurePassportKeys(): void
    {
        if (self::$passportKeys === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            self::$passportKeys = ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
        }
        config(['passport.private_key' => self::$passportKeys['private'], 'passport.public_key' => self::$passportKeys['public']]);
    }
}
