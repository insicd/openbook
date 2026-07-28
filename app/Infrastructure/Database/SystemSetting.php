<?php

namespace App\Infrastructure\Database;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Archivio chiave/valore per la configurazione dell'istanza (nome, stato di
 * installazione, token cron, ecc.), gestito dall'installer e dal pannello di
 * amministrazione. Le chiavi elencate in {@see self::ENCRYPTED_KEYS} vengono
 * cifrate a riposo perche' rappresentano segreti condivisi (non password):
 * l'amministratore deve poterle recuperare per configurare il cron esterno.
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
    ];

    public static function get(string $key, ?string $default = null): ?string
    {
        $row = static::query()->where('key', $key)->first();

        if ($row === null || $row->value === null) {
            return $default;
        }

        if (in_array($key, self::ENCRYPTED_KEYS, true)) {
            try {
                return Crypt::decryptString($row->value);
            } catch (DecryptException) {
                return $default;
            }
        }

        return $row->value;
    }

    public static function put(string $key, ?string $value): void
    {
        $stored = $value !== null && in_array($key, self::ENCRYPTED_KEYS, true)
            ? Crypt::encryptString($value)
            : $value;

        static::query()->updateOrCreate(['key' => $key], ['value' => $stored]);
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
}
