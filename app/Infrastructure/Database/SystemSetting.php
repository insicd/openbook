<?php

namespace App\Infrastructure\Database;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Archivio chiave/valore per la configurazione dell'istanza (nome, stato di
 * installazione, token cron, ecc.), gestito dall'installer e dal pannello di
 * amministrazione. Le chiavi elencate in {@see self::ENCRYPTED_KEYS} vengono
 * cifrate a riposo perche' contengono segreti recuperabili, come il token cron
 * e le chiavi private dei servizi dell'istanza.
 * Le letture HTTP fuori transazione sono memorizzate per chiave nella sola
 * richiesta corrente; CLI e transazioni leggono sempre dal database.
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 */
class SystemSetting extends Model
{
    protected $table = 'system_settings';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'value',
    ];

    private const ENCRYPTED_KEYS = [
        'cron_token',
        'push_vapid_keys',
        'oauth_signing_keys',
        'object_storage_secret',
    ];

    public static function get(string $key, ?string $default = null): ?string
    {
        if (app()->runningInConsole()) {
            return static::readValue($key) ?? $default;
        }

        $attributes = request()->attributes;

        // Do not retain values read from a transaction that may roll back.
        if (static::resolveConnection()->transactionLevel() > 0) {
            $attributes->remove(self::class);
            return static::readValue($key) ?? $default;
        }

        $values = $attributes->get(self::class, []);
        if (array_key_exists($key, $values)) {
            return $values[$key] ?? $default;
        }

        $value = static::readValue($key);
        $values[$key] = $value;
        $attributes->set(self::class, $values);

        return $value ?? $default;
    }

    private static function readValue(string $key): ?string
    {
        $row = static::query()->where('key', $key)->first();

        if ($row === null || $row->value === null) {
            return null;
        }

        if (in_array($key, self::ENCRYPTED_KEYS, true)) {
            try {
                return Crypt::decryptString($row->value);
            } catch (DecryptException) {
                return null;
            }
        }

        return $row->value;
    }

    public static function put(string $key, ?string $value): void
    {
        static::forgetCachedValue($key);
        static::query()->updateOrCreate(['key' => $key], ['value' => self::storedValue($key, $value)]);
    }

    /**
     * Inserisce una configurazione solo se non esiste gia'. Il vincolo unique
     * sulla chiave rende sicura la prima inizializzazione da richieste concorrenti.
     */
    public static function putIfAbsent(string $key, ?string $value): bool
    {
        static::forgetCachedValue($key);
        $now = now();

        return static::query()->insertOrIgnore([
            'key' => $key,
            'value' => self::storedValue($key, $value),
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = static::get($key);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function putBool(string $key, bool $value): void
    {
        static::put($key, $value ? '1' : '0');
    }

    private static function forgetCachedValue(string $key): void
    {
        if (app()->runningInConsole()) {
            return;
        }

        $attributes = request()->attributes;
        $values = $attributes->get(self::class, []);
        unset($values[$key]);
        $attributes->set(self::class, $values);
    }

    private static function storedValue(string $key, ?string $value): ?string
    {
        return $value !== null && in_array($key, self::ENCRYPTED_KEYS, true)
            ? Crypt::encryptString($value)
            : $value;
    }
}
