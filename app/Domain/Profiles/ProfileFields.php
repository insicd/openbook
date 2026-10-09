<?php

namespace App\Domain\Profiles;

final class ProfileFields
{
    public const MAX_FIELDS = 8;

    public const MAX_LABEL_LENGTH = 50;

    public const MAX_VALUE_LENGTH = 1000;

    public const MAX_URL_LENGTH = 255;

    /** @param array{value?: string|null, url?: string|null} $field */
    public static function value(array $field): string
    {
        return (string) ($field['value'] ?? $field['url'] ?? '');
    }

    public static function isLink(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
