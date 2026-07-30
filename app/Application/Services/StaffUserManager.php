<?php

namespace App\Application\Services;

use App\Domain\Accounts\User;
use InvalidArgumentException;

/**
 * Azioni staff sugli account locali: sospensione, disabilitazione e
 * promozione/demozione a moderatore (quest'ultima solo da admin).
 */
final class StaffUserManager
{
    public function suspend(User $actor, User $target): void
    {
        $this->assertCanChangeStatus($actor, $target);
        $target->forceFill(['status' => User::STATUS_SUSPENDED])->save();
    }

    public function unsuspend(User $actor, User $target): void
    {
        $this->assertCanChangeStatus($actor, $target);
        $target->forceFill(['status' => User::STATUS_ACTIVE])->save();
    }

    public function disable(User $actor, User $target): void
    {
        $this->assertCanChangeStatus($actor, $target);
        $target->forceFill(['status' => User::STATUS_DISABLED])->save();
    }

    public function promoteModerator(User $actor, User $target): void
    {
        $this->assertCanChangeModeratorRole($actor, $target);

        if ($target->is_admin) {
            return;
        }

        $target->forceFill(['is_moderator' => true])->save();
    }

    public function demoteModerator(User $actor, User $target): void
    {
        $this->assertCanChangeModeratorRole($actor, $target);
        $target->forceFill(['is_moderator' => false])->save();
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
}
