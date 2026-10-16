<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;

/** Dates for the current web visitor; model timestamps keep their original timezone. */
final class UserDate
{
    public const COOKIE_NAME = 'openbook_timezone';

    public function timezone(): string
    {
        $request = request();

        if (! $request->attributes->has('openbook.display_timezone')) {
            $preferred = $request->user()?->settings?->timezone;
            $browser = $request->cookie(self::COOKIE_NAME);
            $timezone = self::isSupported($preferred) ? $preferred
                : (self::isSupported($browser) ? $browser : (string) config('app.timezone', 'UTC'));
            $request->attributes->set('openbook.display_timezone', $timezone);
        }

        return $request->attributes->get('openbook.display_timezone');
    }

    public function isAutomatic(): bool
    {
        return ! self::isSupported(request()->user()?->settings?->timezone);
    }

    public static function isSupported(mixed $timezone): bool
    {
        // Include IANA aliases still returned by some browsers (e.g. Asia/Calcutta).
        static $identifiers = null;
        $identifiers ??= array_fill_keys(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);

        return is_string($timezone) && isset($identifiers[$timezone]);
    }

    public function local(DateTimeInterface $date, ?string $timezone = null): CarbonImmutable
    {
        return CarbonImmutable::instance($date)
            ->setTimezone($timezone ?? $this->timezone())
            ->locale(app()->getLocale());
    }

    public function format(?DateTimeInterface $date, string $format = 'j M Y, H:i'): string
    {
        return $date === null ? '' : $this->local($date)->translatedFormat($format);
    }
}
