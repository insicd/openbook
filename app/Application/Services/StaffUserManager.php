<?php

namespace App\Application\Services;

use App\Domain\Accounts\User;
use App\Federation\Actors\Actor;
use InvalidArgumentException;

/**
 * Azioni staff sugli account locali: sospensione, disabilitazione e
 * promozione/demozione a moderatore o amministratore (ruoli solo da admin).
 *
 * Sospensione: account non autenticabile, profilo oscurato, escluso da
 * suggerimenti/ricerche. Disabilitazione: profilo non raggiungibile (404).
 */
final class StaffUserManager
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function suspend(User $actor, User $target): void
    {
        $this->assertCanChangeStatus($actor, $target);
        $target->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $this->syncLocalActorStatus($target, User::STATUS_SUSPENDED);
        $this->auditLogger->log($actor, 'user.suspend', $target);
    }

    public function unsuspend(User $actor, User $target): void
    {
        $this->assertCanChangeStatus($actor, $target);
        $target->forceFill(['status' => User::STATUS_ACTIVE])->save();
        $this->syncLocalActorStatus($target, User::STATUS_ACTIVE);
        $this->auditLogger->log($actor, 'user.unsuspend', $target);
    }

    public function disable(User $actor, User $target): void
    {
        $this->assertCanChangeStatus($actor, $target);
        $target->forceFill(['status' => User::STATUS_DISABLED])->save();
        $this->syncLocalActorStatus($target, User::STATUS_DISABLED);
        $this->auditLogger->log($actor, 'user.disable', $target);
    }

    public function promoteModerator(User $actor, User $target): void
    {
        $this->assertCanChangeModeratorRole($actor, $target);

        if ($target->is_admin) {
            return;
        }

        $target->forceFill(['is_moderator' => true])->save();
        $this->auditLogger->log($actor, 'user.promote_moderator', $target);
    }

    public function demoteModerator(User $actor, User $target): void
    {
        $this->assertCanChangeModeratorRole($actor, $target);
        $target->forceFill(['is_moderator' => false])->save();
        $this->auditLogger->log($actor, 'user.demote_moderator', $target);
    }

    public function promoteAdmin(User $actor, User $target): void
    {
        $this->assertCanChangeAdminRole($actor, $target);
        $target->forceFill(['is_admin' => true, 'is_moderator' => true])->save();
        $this->auditLogger->log($actor, 'user.promote_admin', $target);
    }

    public function demoteAdmin(User $actor, User $target): void
    {
        $this->assertCanChangeAdminRole($actor, $target);

        $otherAdmins = User::query()
            ->where('is_admin', true)
            ->whereKeyNot($target->id)
            ->count();

        if ($otherAdmins === 0) {
            throw new InvalidArgumentException('Non puoi rimuovere l ultimo amministratore.');
        }

        $target->forceFill(['is_admin' => false])->save();
        $this->auditLogger->log($actor, 'user.demote_admin', $target);
    }

    private function assertCanChangeStatus(User $actor, User $target): void
    {
        if (! $actor->canModerate()) {
            throw new InvalidArgumentException('Non hai i permessi di moderazione.');
        }

        if ($actor->id === $target->id) {
            throw new InvalidArgumentException('Non puoi modificare il tuo stesso account da qui.');
        }

        if ($target->is_admin) {
            throw new InvalidArgumentException('Non puoi sospendere o disabilitare un amministratore.');
        }

        if ($target->is_moderator && ! $actor->canAdminister()) {
            throw new InvalidArgumentException('Solo un amministratore puo agire sullo status di un moderatore.');
        }
    }

    private function assertCanChangeModeratorRole(User $actor, User $target): void
    {
        if (! $actor->canAdminister()) {
            throw new InvalidArgumentException('Solo un amministratore puo gestire i moderatori.');
        }

        if ($actor->id === $target->id) {
            throw new InvalidArgumentException('Non puoi modificare il tuo stesso account da qui.');
        }

        if ($target->is_admin) {
            throw new InvalidArgumentException('Un amministratore non puo essere gestito come moderatore da qui.');
        }
    }

    private function assertCanChangeAdminRole(User $actor, User $target): void
    {
        if (! $actor->canAdminister()) {
            throw new InvalidArgumentException('Solo un amministratore puo gestire gli amministratori.');
        }

        if ($actor->id === $target->id) {
            throw new InvalidArgumentException('Non puoi modificare il tuo stesso account da qui.');
        }
    }

    private function syncLocalActorStatus(User $target, string $userStatus): void
    {
        $actor = $target->actor;

        if ($actor === null || ! $actor->isLocal()) {
            return;
        }

        $actorStatus = match ($userStatus) {
            User::STATUS_SUSPENDED => Actor::STATUS_SUSPENDED,
            User::STATUS_DISABLED => Actor::STATUS_BLOCKED,
            default => Actor::STATUS_ACTIVE,
        };

        if ($actor->status !== $actorStatus) {
            $actor->forceFill(['status' => $actorStatus])->save();
        }
    }
}
