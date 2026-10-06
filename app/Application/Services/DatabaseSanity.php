<?php

namespace App\Application\Services;

use App\Application\Queries\DatabaseSanityQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class DatabaseSanity
{
    public function __construct(private readonly DatabaseSanityQuery $query) {}

    /** @return array{deleted: int, cursor: ?string} */
    public function reconcileBatch(string $table, string $type, int $batchSize, ?string $afterId = null): array
    {
        if (! in_array($table, ['likes', 'mentions', 'notifications'], true)) {
            throw new InvalidArgumentException('Unsupported sanity cleanup table.');
        }

        $candidates = $this->query->orphans($table, $type, $batchSize, $afterId);
        $ids = (clone $candidates)->pluck('id');
        if ($ids->isEmpty()) {
            return ['deleted' => 0, 'cursor' => null];
        }

        // Recheck the parent and morph type in the DELETE itself: the earlier
        // selection can become stale while another request creates a parent.
        $deleted = $table === 'notifications'
            ? $this->deleteNotifications($candidates, $ids->all())
            : (clone $candidates)->whereIn($table.'.id', $ids->all())->delete();

        return ['deleted' => $deleted, 'cursor' => $ids->last()];
    }

    /** @param list<string> $ids */
    private function deleteNotifications(Builder $candidates, array $ids): int
    {
        return DB::transaction(function () use ($candidates, $ids): int {
            $locked = DB::table('notifications')->whereIn('id', $ids)->orderBy('id')
                ->lockForUpdate()->get(['id', 'recipient_id']);

            // The FK removes associated push rows in this same transaction.
            $deleted = (clone $candidates)->whereIn('notifications.id', $ids)->delete();
            if ($deleted === 0) {
                return 0;
            }

            // A parent may have been restored since selection. Only recipients
            // of actually deleted notifications need their revision bumped.
            $remaining = DB::table('notifications')->whereIn('id', $ids)->pluck('id');
            $recipients = $locked->whereNotIn('id', $remaining->all())
                ->pluck('recipient_id')->unique()->values()->all();
            DB::table('users')->whereIn('id', $recipients)->increment('notifications_revision');

            return $deleted;
        }, 3);
    }
}
