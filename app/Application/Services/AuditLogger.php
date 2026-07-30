<?php

namespace App\Application\Services;

use App\Domain\Accounts\User;
use App\Domain\Moderation\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Scrive voci append-only nel registro azioni staff.
 */
final class AuditLogger
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function log(?User $actor, string $action, ?Model $subject = null, array $meta = []): void
    {
        AuditLog::query()->create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject !== null ? $subject->getMorphClass() : null,
            'subject_id' => $subject?->getKey(),
            'meta' => $meta === [] ? null : $meta,
            'ip' => request()?->ip(),
            'created_at' => now(),
        ]);
    }
}
