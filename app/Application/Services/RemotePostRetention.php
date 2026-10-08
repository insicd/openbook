<?php

namespace App\Application\Services;

use App\Application\Queries\RemotePostRetentionQuery;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class RemotePostRetention
{
    public function __construct(private readonly RemotePostRetentionQuery $query) {}

    /** @return array{nonPertinent: int, pertinent: int, timed_out: bool} */
    public function prune(int $batchSize, int $maxTime, ?CarbonInterface $asOf = null): array
    {
        if ($batchSize < 1 || $maxTime < 1) {
            throw new \InvalidArgumentException('Batch size and time limit must be positive.');
        }

        $deadline = hrtime(true) + $maxTime * 1_000_000_000;
        $asOf ??= now();
        // Freeze both durations and cutoffs in the query builders for this run.
        $queries = [
            'nonPertinent' => $this->query->nonPertinent($batchSize, $asOf),
            'pertinent' => $this->query->pertinent($batchSize, $asOf),
        ];
        $cursors = [];
        $totals = ['nonPertinent' => 0, 'pertinent' => 0, 'timed_out' => false];

        while ($queries !== []) {
            foreach ($queries as $category => $query) {
                if (hrtime(true) >= $deadline) {
                    $totals['timed_out'] = true;

                    return $totals;
                }

                $batch = clone $query;
                if (isset($cursors[$category])) {
                    [$createdAt, $id] = $cursors[$category];
                    $batch->where(function (Builder $after) use ($createdAt, $id): void {
                        $after->where('retention_posts.created_at', '>', $createdAt)
                            ->orWhere(function (Builder $sameTime) use ($createdAt, $id): void {
                                $sameTime->where('retention_posts.created_at', $createdAt)
                                    ->where('retention_posts.id', '>', $id);
                            });
                    });
                }

                $result = $this->pruneBatch($batch);
                $totals[$category] += $result['deleted'];
                if ($result['cursor'] === null) {
                    unset($queries[$category]);
                } else {
                    $cursors[$category] = $result['cursor'];
                }
            }
        }

        return $totals;
    }

    /** @return array{deleted: int, cursor: ?array{string, string}} */
    public function pruneBatch(Builder $candidates): array
    {
        $selected = (clone $candidates)->get();
        $last = $selected->last();
        if ($last === null) {
            return ['deleted' => 0, 'cursor' => null];
        }

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            // Range locks need REPEATABLE READ. Applies only to the next batch
            // transaction, without changing the session or server default.
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        $deleted = DB::transaction(function () use ($selected, $candidates): int {
            // Lock roots in a stable order, then the indexed dependent ranges.
            $ids = DB::table('posts')->whereIn('id', $selected->pluck('id')->all())
                ->orderBy('id')->lockForUpdate()->pluck('id')->all();

            // Explicit range locks also cover MySQL's SQL-layer FK handling,
            // which does not make FK writers wait on a parent row lock alone.
            DB::table('comments')->whereIn('post_id', $ids)
                ->orderBy('post_id')->lockForUpdate()->get(['id']);
            DB::table('posts')->whereIn('quoted_post_id', $ids)
                ->orderBy('quoted_post_id')->lockForUpdate()->get(['id']);

            // The initial selection was outside the transaction. Recheck the
            // full policy after locking, before issuing the aggregate DELETE.
            $eligible = (clone $candidates)->whereIn('retention_posts.id', $ids)->pluck('id')->all();
            if ($eligible === []) {
                return 0;
            }

            // Every reply already references its root through post_id. Detach
            // only doomed threads to avoid recursive self-cascades in MySQL.
            DB::table('comments')->whereIn('post_id', $eligible)
                ->whereNotNull('parent_comment_id')->update(['parent_comment_id' => null]);

            // Query builder deliberately bypasses model events/federated deletion.
            return DB::table('posts')->whereIn('id', $eligible)->delete();
        });

        return ['deleted' => $deleted, 'cursor' => [$last->created_at, $last->id]];
    }
}
