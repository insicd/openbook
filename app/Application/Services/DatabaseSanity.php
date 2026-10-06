<?php

namespace App\Application\Services;

use App\Application\Queries\DatabaseSanityQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class DatabaseSanity
{
    public function __construct(private readonly DatabaseSanityQuery $query) {}

    /** @return array{deleted: array<string, array<string, int>>, timed_out: bool} */
    public function reconcile(int $batchSize, int $maxTime): array
    {
        if ($batchSize < 1 || $maxTime < 1) {
            throw new InvalidArgumentException('Batch size and time limit must be positive.');
        }

        $deadline = hrtime(true) + $maxTime * 1_000_000_000;
        $targets = [];
        $totals = [];
        foreach ($this->query->relations() as $table => $relation) {
            foreach (array_keys($relation['parents']) as $type) {
                $targets[$table.'/'.$type] = [$table, $type, null];
                $totals[$table][$type] = 0;
            }
        }

        while ($targets !== []) {
            foreach ($targets as $key => [$table, $type, $cursor]) {
                if (hrtime(true) >= $deadline) {
                    return ['deleted' => $totals, 'timed_out' => true];
                }

                $result = $this->reconcileBatch($table, $type, $batchSize, $cursor);
                $totals[$table][$type] += $result['deleted'];
                if ($result['cursor'] === null) {
                    unset($targets[$key]);
                } else {
                    $targets[$key][2] = $result['cursor'];
                }
            }
        }

        return ['deleted' => $totals, 'timed_out' => false];
    }

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
