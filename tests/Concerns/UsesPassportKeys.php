<?php

namespace Tests\Concerns;

use App\Infrastructure\Auth\OAuthSigningKeys;
use App\Infrastructure\Database\SystemSetting;

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
        SystemSetting::put(OAuthSigningKeys::SETTING_KEY, json_encode(['privateKey' => self::$passportKeys['private'], 'publicKey' => self::$passportKeys['public']], JSON_THROW_ON_ERROR));
    }
}
