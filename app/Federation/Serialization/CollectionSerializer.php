<?php

namespace App\Federation\Serialization;

/**
 * Helper per le collezioni paginate ActivityStreams (OrderedCollection /
 * OrderedCollectionPage), usate da outbox, followers e following.
 */
final class CollectionSerializer
{
    /**
     * @return array<string, mixed>
     */
    public static function collection(string $id, int $totalItems, string $firstPageUrl): array
    {
        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $id,
            'type' => 'OrderedCollection',
            'totalItems' => $totalItems,
            'first' => $firstPageUrl,
        ];
    }

    /**
     * @param  list<mixed>  $items
     * @return array<string, mixed>
     */
    public static function page(string $id, string $partOf, array $items, ?string $next = null, ?string $prev = null): array
    {
        $page = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $id,
            'type' => 'OrderedCollectionPage',
            'partOf' => $partOf,
            'orderedItems' => $items,
        ];

        if ($next !== null) {
            $page['next'] = $next;
        }

        if ($prev !== null) {
            $page['prev'] = $prev;
        }

        return $page;
    }
}
