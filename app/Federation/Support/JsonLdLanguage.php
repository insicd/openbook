<?php

namespace App\Federation\Support;

use App\Domain\Posts\PostLanguage;

/** Default espliciti locali: nessun fetch o espansione di contesti remoti. */
final class JsonLdLanguage
{
    /**
     * Conserva l'ordine dei contesti quando si estrae un oggetto incorporato.
     * Documenti recuperati via HTTP hanno invece un proprio contesto indipendente.
     *
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $parent
     * @return array<string, mixed>
     */
    public static function inherit(array $document, array $parent): array
    {
        if (! array_key_exists('@context', $parent)) {
            return $document;
        }

        $contexts = self::entries($parent['@context']);

        if (array_key_exists('@context', $document)) {
            $contexts = array_merge($contexts, self::entries($document['@context']));
        }

        $document['@context'] = $contexts;

        return $document;
    }

    public static function defaultLanguage(mixed $context): ?string
    {
        $language = null;
        $unsupported = false;

        foreach (self::entries($context) as $entry) {
            if ($entry === null) {
                $language = null;
                $unsupported = false;
            } elseif (is_string($entry)) {
                if (! in_array($entry, ['https://www.w3.org/ns/activitystreams', 'http://www.w3.org/ns/activitystreams'], true)) {
                    // Un contesto sconosciuto potrebbe ridefinire il default.
                    $language = null;
                    $unsupported = true;
                }
            } elseif (is_array($entry) && ! array_is_list($entry)) {
                // Alias, import e contesti scoped richiedono un vero processor JSON-LD.
                if (array_key_exists('content', $entry) || array_key_exists('contentMap', $entry)
                    || array_key_exists('object', $entry) || array_key_exists('@import', $entry)
                    || array_key_exists('@propagate', $entry) || array_key_exists('@vocab', $entry)) {
                    $unsupported = true;
                }

                foreach ($entry as $definition) {
                    if (is_array($definition) && array_key_exists('@context', $definition)) {
                        $unsupported = true;
                    }
                }

                if (array_key_exists('@language', $entry)) {
                    $language = PostLanguage::normalize($entry['@language']);
                }
            } else {
                $language = null;
                $unsupported = true;
            }
        }

        return $unsupported ? null : $language;
    }

    /** @return list<mixed> */
    private static function entries(mixed $context): array
    {
        return is_array($context) && array_is_list($context) ? $context : [$context];
    }
}
