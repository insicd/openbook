<?php

namespace App\Application\Services;

use App\Application\Queries\DatabaseSanityQuery;
use InvalidArgumentException;

final class DatabaseSanity
{
    public function __construct(private readonly DatabaseSanityQuery $query) {}

    /** @return array{deleted: int, cursor: ?string} */
    public function reconcileBatch(string $table, string $type, int $batchSize, ?string $afterId = null): array
    {
        if (! in_array($table, ['likes', 'mentions'], true)) {
            throw new InvalidArgumentException('Unsupported sanity cleanup table.');
        }

        $candidates = $this->query->orphans($table, $type, $batchSize, $afterId);
        $ids = (clone $candidates)->pluck('id');
        if ($ids->isEmpty()) {
            return ['deleted' => 0, 'cursor' => null];
        }

        // Recheck the parent and morph type in the DELETE itself: the earlier
        // selection can become stale while another request creates a parent.
        $deleted = (clone $candidates)->whereIn($table.'.id', $ids->all())->delete();

        return ['deleted' => $deleted, 'cursor' => $ids->last()];
    }
}
