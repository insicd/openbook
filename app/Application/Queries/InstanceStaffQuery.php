<?php

namespace App\Application\Queries;

use App\Domain\Accounts\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Amministratori e moderatori locali attivi, per la scheda "Questa istanza"
 * sulla home pubblica.
 */
final class InstanceStaffQuery
{
    /**
     * @return Collection<int, User>
     */
    public function all(): Collection
    {
        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->where(static function ($query): void {
                $query->where('is_admin', true)
                    ->orWhere('is_moderator', true);
            })
            ->with('profile')
            ->orderByDesc('is_admin')
            ->orderBy('username')
            ->get();
    }
}
