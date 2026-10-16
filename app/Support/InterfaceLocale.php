<?php

namespace App\Support;

final class InterfaceLocale
{
    public const COOKIE_NAME = 'openbook_locale';

    public const COOKIE_MINUTES = 60 * 24 * 365;

    public static function isSupported(mixed $locale): bool
    {
        return is_string($locale) && array_key_exists($locale, (array) config('openbook.locales'));
    }
}
