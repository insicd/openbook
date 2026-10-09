<?php

namespace App\Federation\Serialization;

use App\Domain\Profiles\ProfileFields;

final class ProfileFieldSerializer
{
    /**
     * @param  array<int, array{label: string, value?: string, url?: string}>  $links
     * @return array<int, array{name: string, value: string}>
     */
    public static function links(array $links): array
    {
        return array_map(static function (array $link): array {
            $value = ProfileFields::value($link);

            return [
                'name' => (string) ($link['label'] ?? $value),
                'value' => ProfileFields::isLink($value)
                    ? '<a href="'.e($value).'" rel="me nofollow noopener" target="_blank">'.e($value).'</a>'
                    : nl2br(e($value), false),
            ];
        }, $links);
    }
}
