<?php

namespace App\Application\Services;

use App\Infrastructure\Database\SystemSetting;
use App\Infrastructure\Installation\EnvironmentFileWriter;
use Illuminate\Support\Facades\Config;

/**
 * Impostazioni istanza gestibili dal pannello admin: source of truth in
 * {@see SystemSetting}, con fallback a config/.env e sync di APP_NAME.
 */
final class InstanceSettings
{
    public const KEY_SITE_NAME = 'site_name';

    public const KEY_REGISTRATION_OPEN = 'registration_open';

    public function __construct(
        private readonly EnvironmentFileWriter $envWriter,
    ) {}

    /**
     * Applica i valori salvati in DB sopra la config runtime (dopo installazione).
     */
    public function applyToRuntimeConfig(): void
    {
        if (! config('openbook.installed')) {
            return;
        }

        $siteName = SystemSetting::get(self::KEY_SITE_NAME);
        if (filled($siteName)) {
            Config::set('app.name', $siteName);
        }

        $registration = SystemSetting::get(self::KEY_REGISTRATION_OPEN);
        if ($registration !== null) {
            Config::set('openbook.registration.open', filter_var($registration, FILTER_VALIDATE_BOOLEAN));
        }
    }

    public function siteName(): string
    {
        return SystemSetting::get(self::KEY_SITE_NAME) ?: (string) config('app.name');
    }

    public function registrationOpen(): bool
    {
        $stored = SystemSetting::get(self::KEY_REGISTRATION_OPEN);

        if ($stored === null) {
            return (bool) config('openbook.registration.open');
        }

        return filter_var($stored, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  array{site_name: string, registration_open: bool}  $data
     */
    public function update(array $data): void
    {
        $siteName = trim($data['site_name']);
        $registrationOpen = (bool) $data['registration_open'];

        SystemSetting::put(self::KEY_SITE_NAME, $siteName);
        SystemSetting::putBool(self::KEY_REGISTRATION_OPEN, $registrationOpen);

        Config::set('app.name', $siteName);
        Config::set('openbook.registration.open', $registrationOpen);

        if (! app()->runningUnitTests() && is_file(base_path('.env'))) {
            $this->envWriter->update([
                'APP_NAME' => $siteName,
                'OPENBOOK_REGISTRATION_OPEN' => $registrationOpen ? 'true' : 'false',
            ]);
        }
    }
}
