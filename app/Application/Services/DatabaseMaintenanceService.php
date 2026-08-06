<?php

namespace App\Application\Services;

use App\Domain\Accounts\User;
use App\Federation\Inbox\InboxItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Statistiche e pulizia sicura di tabelle operative che crescono nel tempo
 * (inbox grezzo, job falliti, cache/sessioni). Conserva sempre le righe
 * delle ultime {@see RETENTION_HOURS} ore; non tocca dati di dominio
 * (post, utenti, federazione).
 */
final class DatabaseMaintenanceService
{
    public const RETENTION_HOURS = 24;

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return list<array{
     *     key: string,
     *     table: string,
     *     label: string,
     *     description: string,
     *     available: bool,
     *     row_count: int,
     *     size_bytes: int,
     *     size_label: string,
     *     purgeable_count: int
     * }>
     */
    public function snapshots(): array
    {
        $snapshots = [];

        foreach ($this->definitions() as $definition) {
            $table = $definition['table'];

            if (! Schema::hasTable($table)) {
                continue;
            }

            $sizeBytes = $this->tableSizeBytes($table);

            $snapshots[] = [
                'key' => $definition['key'],
                'table' => $table,
                'label' => __($definition['label_key']),
                'description' => __($definition['description_key'], ['hours' => self::RETENTION_HOURS]),
                'available' => true,
                'row_count' => (int) DB::table($table)->count(),
                'size_bytes' => $sizeBytes,
                'size_label' => $this->formatBytes($sizeBytes),
                'purgeable_count' => $this->purgeableCount($definition['key']),
            ];
        }

        return $snapshots;
    }

    /**
     * @return array<string, int>
     */
    public function purgeAll(?User $actor): array
    {
        $deleted = [];

        foreach ($this->definitions() as $definition) {
            if (! Schema::hasTable($definition['table'])) {
                continue;
            }

            $deleted[$definition['key']] = $this->purgeKey($definition['key']);
        }

        if ($actor !== null && array_sum($deleted) > 0) {
            $this->auditLogger->log($actor, 'database.purge', null, [
                'retention_hours' => self::RETENTION_HOURS,
                'deleted' => $deleted,
            ]);
        }

        return $deleted;
    }

    public function purgeKey(string $key, ?User $actor = null): int
    {
        $definition = $this->definitionForKey($key);

        if ($definition === null || ! Schema::hasTable($definition['table'])) {
            return 0;
        }

        $deleted = match ($key) {
            'inbox_items' => $this->purgeInboxItems(),
            'failed_jobs' => $this->purgeFailedJobs(),
            'cache' => $this->purgeCache(),
            'cache_locks' => $this->purgeCacheLocks(),
            'sessions' => $this->purgeSessions(),
            'password_reset_tokens' => $this->purgePasswordResetTokens(),
            default => 0,
        };

        if ($actor !== null && $deleted > 0) {
            $this->auditLogger->log($actor, 'database.purge', null, [
                'table' => $key,
                'retention_hours' => self::RETENTION_HOURS,
                'deleted' => $deleted,
            ]);
        }

        return $deleted;
    }

    public function purgeableCount(string $key): int
    {
        $definition = $this->definitionForKey($key);

        if ($definition === null || ! Schema::hasTable($definition['table'])) {
            return 0;
        }

        return match ($key) {
            'inbox_items' => $this->inboxItemsPurgeableQuery()->count(),
            'failed_jobs' => $this->failedJobsPurgeableQuery()->count(),
            'cache' => $this->cachePurgeableQuery()->count(),
            'cache_locks' => $this->cacheLocksPurgeableQuery()->count(),
            'sessions' => $this->sessionsPurgeableQuery()->count(),
            'password_reset_tokens' => $this->passwordResetTokensPurgeableQuery()->count(),
            default => 0,
        };
    }

    /**
     * @return list<array{key: string, table: string, label_key: string, description_key: string}>
     */
    private function definitions(): array
    {
        return [
            [
                'key' => 'inbox_items',
                'table' => 'inbox_items',
                'label_key' => 'openbook.admin.database.tables.inbox_items',
                'description_key' => 'openbook.admin.database.tables.inbox_items_help',
            ],
            [
                'key' => 'failed_jobs',
                'table' => 'failed_jobs',
                'label_key' => 'openbook.admin.database.tables.failed_jobs',
                'description_key' => 'openbook.admin.database.tables.failed_jobs_help',
            ],
            [
                'key' => 'cache',
                'table' => 'cache',
                'label_key' => 'openbook.admin.database.tables.cache',
                'description_key' => 'openbook.admin.database.tables.cache_help',
            ],
            [
                'key' => 'cache_locks',
                'table' => 'cache_locks',
                'label_key' => 'openbook.admin.database.tables.cache_locks',
                'description_key' => 'openbook.admin.database.tables.cache_locks_help',
            ],
            [
                'key' => 'sessions',
                'table' => 'sessions',
                'label_key' => 'openbook.admin.database.tables.sessions',
                'description_key' => 'openbook.admin.database.tables.sessions_help',
            ],
            [
                'key' => 'password_reset_tokens',
                'table' => 'password_reset_tokens',
                'label_key' => 'openbook.admin.database.tables.password_reset_tokens',
                'description_key' => 'openbook.admin.database.tables.password_reset_tokens_help',
            ],
        ];
    }

    /**
     * @return array{key: string, table: string, label_key: string, description_key: string}|null
     */
    private function definitionForKey(string $key): ?array
    {
        foreach ($this->definitions() as $definition) {
            if ($definition['key'] === $key) {
                return $definition;
            }
        }

        return null;
    }

    private function cutoff(): Carbon
    {
        return now()->subHours(self::RETENTION_HOURS);
    }

    private function purgeInboxItems(): int
    {
        return $this->inboxItemsPurgeableQuery()->delete();
    }

    private function purgeFailedJobs(): int
    {
        return $this->failedJobsPurgeableQuery()->delete();
    }

    private function purgeCache(): int
    {
        return $this->cachePurgeableQuery()->delete();
    }

    private function purgeCacheLocks(): int
    {
        return $this->cacheLocksPurgeableQuery()->delete();
    }

    private function purgeSessions(): int
    {
        return $this->sessionsPurgeableQuery()->delete();
    }

    private function purgePasswordResetTokens(): int
    {
        return $this->passwordResetTokensPurgeableQuery()->delete();
    }

    private function inboxItemsPurgeableQuery()
    {
        return InboxItem::query()
            ->whereIn('status', [
                InboxItem::STATUS_PROCESSED,
                InboxItem::STATUS_IGNORED,
                InboxItem::STATUS_FAILED,
            ])
            ->where('received_at', '<', $this->cutoff());
    }

    private function failedJobsPurgeableQuery()
    {
        return DB::table('failed_jobs')->where('failed_at', '<', $this->cutoff());
    }

    private function cachePurgeableQuery()
    {
        return DB::table('cache')->where('expiration', '<', $this->cutoff()->timestamp);
    }

    private function cacheLocksPurgeableQuery()
    {
        return DB::table('cache_locks')->where('expiration', '<', $this->cutoff()->timestamp);
    }

    private function sessionsPurgeableQuery()
    {
        return DB::table('sessions')->where('last_activity', '<', $this->cutoff()->timestamp);
    }

    private function passwordResetTokensPurgeableQuery()
    {
        return DB::table('password_reset_tokens')
            ->where(function ($query) {
                $query->where('created_at', '<', $this->cutoff())
                    ->orWhereNull('created_at');
            });
    }

    private function tableSizeBytes(string $table): int
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $row = DB::selectOne(
                'SELECT (COALESCE(data_length, 0) + COALESCE(index_length, 0)) AS size_bytes
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = ?',
                [$table],
            );

            return (int) ($row->size_bytes ?? 0);
        }

        return 0;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / 1024 / 1024, 1).' MB';
        }

        return round($bytes / 1024 / 1024 / 1024, 2).' GB';
    }
}
