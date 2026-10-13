<?php

namespace App\Federation\Support;

/**
 * Legge un booleano da un documento ActivityPub, sia in forma JSON
 * compatta (`true`) sia in JSON-LD espanso (`{"@value": "true"}`).
 */
final class ActivityPubBoolean
{
    public static function isPresent(array $document, string $key, string ...$iris): bool
    {
        if (array_key_exists($key, $document)) {
            return true;
        }

        foreach ($iris as $iri) {
            if (array_key_exists($iri, $document)) {
                return true;
            }
        }

        return false;
    }

    public static function fromDocument(array $document, string $key, string ...$iris): bool
    {
        if (array_key_exists($key, $document)) {
            return self::parse($document[$key]);
        }

        foreach ($iris as $iri) {
            if (array_key_exists($iri, $document)) {
                return self::parse($document[$iri]);
            }
        }

        return false;
    }

    public static function parse(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['true', '1', 'yes'], true);
        }

        if (! is_array($value)) {
            return false;
        }

        if (array_key_exists('@value', $value)) {
            return self::parse($value['@value']);
        }

        if (array_is_list($value) && $value !== []) {
            return self::parse($value[0]);
        }

        return false;
    }
}
