<?php

namespace App\Support;

use App\Domain\Posts\PostLanguage;
use Illuminate\Support\Str;
use Symfony\Component\Intl\Exception\MissingResourceException;
use Symfony\Component\Intl\Languages;
use Symfony\Component\Intl\Locales;

final class PostLanguageLabel
{
    public static function name(string $tag, string $displayLocale): string
    {
        $normalized = PostLanguage::normalize($tag);

        if ($normalized === null) {
            return $tag;
        }

        // CLDR non include cmn: conservarne la distinzione da zh e yue.
        if ($normalized === 'cmn') {
            return __('openbook.language_names.cmn', [], $displayLocale);
        }

        $parts = explode('-', $normalized);
        foreach ($parts as $index => $part) {
            if ($index > 0 && ctype_alpha($part)) {
                $parts[$index] = strlen($part) === 4 ? ucfirst($part)
                    : (strlen($part) === 2 ? strtoupper($part) : $part);
            }
        }

        try {
            return Str::ucfirst(Locales::getName(implode('_', $parts), $displayLocale));
        } catch (MissingResourceException) {
            // Non ridurre un tag composto alla lingua base, perdendo le varianti.
            if (count($parts) === 1) {
                try {
                    return Str::ucfirst(Languages::getAlpha3Name($normalized, $displayLocale));
                } catch (MissingResourceException) {
                    // Il codice originale resta un'etichetta utilizzabile.
                }
            }

            return $tag;
        }
    }
}
