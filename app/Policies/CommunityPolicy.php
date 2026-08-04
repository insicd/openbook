<?php

namespace App\Policies;

use App\Domain\Accounts\User;
use App\Domain\Communities\Community;

class CommunityPolicy
{
    public function create(User $user): bool
    {
        return $user->isActive();
    }

    /**
     * Pagina community (nome, descrizione, iscrizione): le private restano
     * raggiungibili dal link diretto cosi' si puo' richiedere l'accesso.
     * Il wall e' protetto da {@see viewWall()}.
     */
    public function view(?User $user, Community $community): bool
    {
        return true;
    }

    /**
     * Contenuti del wall: pubbliche per tutti; private solo a proprietario
     * e membri accettati.
     */
    public function viewWall(?User $user, Community $community): bool
    {
        if (! $community->is_private) {
            return true;
        }

        if ($user === null || $user->actor === null) {
            return false;
        }

        return $community->isOwnedBy($user) || $community->isMember($user->actor);
    }

    /**
     * Elenco membri: solo per le community pubbliche (le private non espongono
     * la membership oltre al contatore sulla pagina).
     */
    public function viewMembers(?User $user, Community $community): bool
    {
        return ! $community->is_private;
    }

    public function update(User $user, Community $community): bool
    {
        return $community->isOwnedBy($user);
    }

    public function join(User $user, Community $community): bool
    {
        return $user->isActive() && $user->actor !== null && ! $community->isOwnedBy($user);
    }

    public function leave(User $user, Community $community): bool
    {
        return $user->actor !== null
            && ! $community->isOwnedBy($user)
            && $community->isMember($user->actor);
    }

    public function moderate(User $user, Community $community): bool
    {
        return $community->isModerator($user) || $user->canModerate();
    }

    public function manageModerators(User $user, Community $community): bool
    {
        return $community->isOwnedBy($user);
    }

    public function post(User $user, Community $community): bool
    {
        return $user->actor !== null && (
            $community->isOwnedBy($user) || $community->isMember($user->actor)
        );
    }
}
