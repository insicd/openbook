<?php

namespace App\Infrastructure\Push;

use App\Infrastructure\Database\SystemSetting;
use JsonException;
use Minishlink\WebPush\VAPID;
use RuntimeException;

final class VapidKeyManager
{
    private const SETTING_KEY = 'push_vapid_keys';

    /** @return array{publicKey: string, privateKey: string} */
    public function getOrCreate(): array
    {
        $stored = SystemSetting::get(self::SETTING_KEY);

        if ($stored === null) {
            $generated = VAPID::createVapidKeys();
            SystemSetting::putIfAbsent(
                self::SETTING_KEY,
                json_encode($generated, JSON_THROW_ON_ERROR),
            );

            // Un'altra richiesta potrebbe aver vinto l'inserimento concorrente.
            $stored = SystemSetting::get(self::SETTING_KEY);
        }

        if ($stored === null) {
            throw new RuntimeException('Unable to initialize the VAPID keys.');
        }

        try {
            $keys = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The stored VAPID keys are invalid.', previous: $exception);
        }

        if (! is_array($keys)
            || ! is_string($keys['publicKey'] ?? null)
            || ! is_string($keys['privateKey'] ?? null)
            || $keys['publicKey'] === ''
            || $keys['privateKey'] === '') {
            throw new RuntimeException('The stored VAPID keys are invalid.');
        }

        return ['publicKey' => $keys['publicKey'], 'privateKey' => $keys['privateKey']];
    }
}
