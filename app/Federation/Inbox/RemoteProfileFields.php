<?php

namespace App\Federation\Inbox;

final class RemoteProfileFields
{
    private const MAX_FIELDS = 16;

    private const MAX_LABEL_LENGTH = 100;

    private const MAX_VALUE_LENGTH = 1000;

    /**
     * @param  array<string, mixed>  $document
     * @return list<array{label: string, value: string}>
     */
    public static function extract(array $document): array
    {
        $attachments = $document['attachment'] ?? [];

        if (! is_array($attachments)) {
            return [];
        }

        if (isset($attachments['type'])) {
            $attachments = [$attachments];
        }

        $fields = [];

        foreach ($attachments as $attachment) {
            if (! is_array($attachment)
                || ! in_array($attachment['type'] ?? null, [
                    'PropertyValue', 'schema:PropertyValue',
                    'http://schema.org#PropertyValue', 'https://schema.org/PropertyValue',
                ], true)
                || ! is_string($attachment['name'] ?? null)
                || ! is_string($attachment['value'] ?? null)) {
                continue;
            }

            $label = trim(html_entity_decode(strip_tags(mb_substr($attachment['name'], 0, 1000)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            // Keep the existing safe text/link pipeline, never the remote HTML.
            $value = RemoteContentSanitizer::toPlainText(mb_substr($attachment['value'], 0, 16384));

            if ($label === '' || $value === '') {
                continue;
            }

            $fields[] = [
                'label' => mb_substr($label, 0, self::MAX_LABEL_LENGTH),
                'value' => mb_substr($value, 0, self::MAX_VALUE_LENGTH),
            ];

            if (count($fields) >= self::MAX_FIELDS) {
                break;
            }
        }

        return $fields;
    }
}
