<?php

namespace App\Federation\Serialization;

final class ProfileFieldSerializer
{
    /**
     * @param  array<int, array{label: string, url: string}>  $links
     * @return array<int, array{name: string, value: string}>
     */
    public static function links(array $links): array
    {
        return array_map(static fn (array $link): array => [
            'name' => (string) ($link['label'] ?? $link['url']),
            'value' => '<a href="'.e($link['url']).'" rel="me nofollow noopener" target="_blank">'.e($link['url']).'</a>',
        ], $links);
    }
}
