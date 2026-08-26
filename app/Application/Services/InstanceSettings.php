<?php

namespace App\Application\Services;

use App\Domain\Accounts\User;
use App\Infrastructure\Appearance\CustomCssSanitizer;
use App\Infrastructure\Database\SystemSetting;
use App\Infrastructure\Media\InstanceIconUploader;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

/**
 * Impostazioni istanza gestibili dal pannello admin: source of truth in
 * {@see SystemSetting}. I valori in .env/config restano default di bootstrap
 * (installazione); a runtime {@see applyToRuntimeConfig()} applica gli override
 * salvati in DB. Il pannello admin non scrive mai su .env.
 */
final class InstanceSettings
{
    public const KEY_SITE_NAME = 'site_name';

    public const KEY_REGISTRATION_OPEN = 'registration_open';

    public const KEY_INSTANCE_RULES = 'instance_rules';

    public const KEY_PRIVACY_POLICY = 'privacy_policy';

    public const KEY_POST_MAX_LENGTH = 'post_max_length';

    public const KEY_COMMENT_MAX_LENGTH = 'comment_max_length';

    public const KEY_MEDIA_MAX_SIZE_KB = 'media_max_size_kb';

    public const KEY_MEDIA_MAX_ATTACHMENTS = 'media_max_attachments';

    public const KEY_SHOW_HOME_STAFF = 'show_home_staff';

    public const KEY_INSTANCE_ICON_DIR = 'instance_icon_dir';

    public const KEY_CUSTOM_CSS = 'custom_css';

    public const CUSTOM_CSS_MAX_LENGTH = 50000;

    /**
     * Favicon di default (SVG inline) usata finche' l'amministratore non
     * carica un'icona personalizzata.
     */
    public const DEFAULT_FAVICON_HREF = "data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='20' fill='%231877f2'/><text x='50' y='68' font-size='56' text-anchor='middle' fill='white' font-family='system-ui,sans-serif' font-weight='700'>O</text></svg>";

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly CustomCssSanitizer $cssSanitizer,
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

        $this->applyIntSetting(self::KEY_POST_MAX_LENGTH, 'openbook.posts.max_length');
        $this->applyIntSetting(self::KEY_COMMENT_MAX_LENGTH, 'openbook.comments.max_length');
        $this->applyIntSetting(self::KEY_MEDIA_MAX_SIZE_KB, 'openbook.media.max_size_kb');
        $this->applyIntSetting(self::KEY_MEDIA_MAX_ATTACHMENTS, 'openbook.media.max_attachments_per_post');
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

    public function instanceRules(): string
    {
        return (string) (SystemSetting::get(self::KEY_INSTANCE_RULES) ?? '');
    }

    public function privacyPolicy(): string
    {
        return (string) (SystemSetting::get(self::KEY_PRIVACY_POLICY) ?? '');
    }

    public function postMaxLength(): int
    {
        return $this->intSetting(self::KEY_POST_MAX_LENGTH, (int) config('openbook.posts.max_length'));
    }

    public function commentMaxLength(): int
    {
        return $this->intSetting(self::KEY_COMMENT_MAX_LENGTH, (int) config('openbook.comments.max_length', 2000));
    }

    public function mediaMaxSizeKb(): int
    {
        return $this->intSetting(self::KEY_MEDIA_MAX_SIZE_KB, (int) config('openbook.media.max_size_kb'));
    }

    public function mediaMaxAttachments(): int
    {
        return $this->intSetting(self::KEY_MEDIA_MAX_ATTACHMENTS, (int) config('openbook.media.max_attachments_per_post'));
    }

    /**
     * Blocco "Amministrazione" sulla home guest (elenco admin/moderatori).
     * Default true: comportamento storico se la chiave non e' ancora in DB.
     */
    public function showHomeStaff(): bool
    {
        $stored = SystemSetting::get(self::KEY_SHOW_HOME_STAFF);

        if ($stored === null) {
            return true;
        }

        return filter_var($stored, FILTER_VALIDATE_BOOLEAN);
    }

    public function iconDirectory(): ?string
    {
        $directory = SystemSetting::get(self::KEY_INSTANCE_ICON_DIR);

        return InstanceIconUploader::isValidDirectory($directory) ? $directory : null;
    }

    public function hasCustomIcons(): bool
    {
        return $this->iconDirectory() !== null;
    }

    public function faviconUrl(): ?string
    {
        return $this->iconPublicUrl('favicon-32.png');
    }

    public function appleTouchIconUrl(): ?string
    {
        return $this->iconPublicUrl('apple-touch-icon.png');
    }

    public function androidIconUrl(int $size): ?string
    {
        return $this->iconPublicUrl('icon-'.$size.'.png');
    }

    public function maskableIconUrl(int $size): ?string
    {
        return $this->iconPublicUrl('icon-'.$size.'-maskable.png');
    }

    public function customCss(): string
    {
        return $this->cssSanitizer->sanitize((string) (SystemSetting::get(self::KEY_CUSTOM_CSS) ?? ''));
    }

    public function updateCustomCss(string $css, ?User $actor = null): void
    {
        $css = $this->cssSanitizer->sanitize($css);

        if (mb_strlen($css) > self::CUSTOM_CSS_MAX_LENGTH) {
            $css = mb_substr($css, 0, self::CUSTOM_CSS_MAX_LENGTH);
        }

        SystemSetting::put(self::KEY_CUSTOM_CSS, $css);

        if ($actor !== null) {
            $this->auditLogger->log($actor, 'appearance.update', null, [
                'css_length' => mb_strlen($css),
            ]);
        }
    }

    /**
     * @param  array{
     *     site_name: string,
     *     registration_open: bool,
     *     show_home_staff: bool,
     *     instance_rules?: string,
     *     privacy_policy?: string,
     *     post_max_length: int,
     *     comment_max_length: int,
     *     media_max_size_kb: int,
     *     media_max_attachments: int,
     *     instance_icon_dir?: string|null
     * }  $data
     */
    public function update(array $data, ?User $actor = null): void
    {
        $siteName = trim($data['site_name']);
        $registrationOpen = (bool) $data['registration_open'];
        $showHomeStaff = (bool) $data['show_home_staff'];
        $rules = (string) ($data['instance_rules'] ?? '');
        $privacyPolicy = (string) ($data['privacy_policy'] ?? '');
        $postMax = (int) $data['post_max_length'];
        $commentMax = (int) $data['comment_max_length'];
        $mediaKb = (int) $data['media_max_size_kb'];
        $mediaAttachments = (int) $data['media_max_attachments'];

        SystemSetting::put(self::KEY_SITE_NAME, $siteName);
        SystemSetting::putBool(self::KEY_REGISTRATION_OPEN, $registrationOpen);
        SystemSetting::putBool(self::KEY_SHOW_HOME_STAFF, $showHomeStaff);
        SystemSetting::put(self::KEY_INSTANCE_RULES, $rules);
        SystemSetting::put(self::KEY_PRIVACY_POLICY, $privacyPolicy);
        SystemSetting::put(self::KEY_POST_MAX_LENGTH, (string) $postMax);
        SystemSetting::put(self::KEY_COMMENT_MAX_LENGTH, (string) $commentMax);
        SystemSetting::put(self::KEY_MEDIA_MAX_SIZE_KB, (string) $mediaKb);
        SystemSetting::put(self::KEY_MEDIA_MAX_ATTACHMENTS, (string) $mediaAttachments);

        if (array_key_exists('instance_icon_dir', $data)) {
            SystemSetting::put(self::KEY_INSTANCE_ICON_DIR, $data['instance_icon_dir']);
        }

        Config::set('app.name', $siteName);
        Config::set('openbook.registration.open', $registrationOpen);
        Config::set('openbook.posts.max_length', $postMax);
        Config::set('openbook.comments.max_length', $commentMax);
        Config::set('openbook.media.max_size_kb', $mediaKb);
        Config::set('openbook.media.max_attachments_per_post', $mediaAttachments);

        if ($actor !== null) {
            $this->auditLogger->log($actor, 'settings.update', null, [
                'site_name' => $siteName,
                'registration_open' => $registrationOpen,
                'show_home_staff' => $showHomeStaff,
                'has_custom_icons' => $this->hasCustomIcons(),
            ]);
        }
    }

    private function iconPublicUrl(string $filename): ?string
    {
        $directory = $this->iconDirectory();

        if ($directory === null) {
            return null;
        }

        return Storage::disk('public')->url($directory.'/'.$filename);
    }

    private function applyIntSetting(string $key, string $configKey): void
    {
        $stored = SystemSetting::get($key);

        if ($stored !== null && is_numeric($stored)) {
            Config::set($configKey, (int) $stored);
        }
    }

    private function intSetting(string $key, int $fallback): int
    {
        $stored = SystemSetting::get($key);

        if ($stored !== null && is_numeric($stored)) {
            return (int) $stored;
        }

        return $fallback;
    }
}
