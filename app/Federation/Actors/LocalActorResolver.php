<?php

namespace App\Federation\Actors;

/**
 * Risolve Actor locali attivi per preferred_username (Person o Group).
 */
final class LocalActorResolver
{
    public function findByUsername(string $username): ?Actor
    {
        return Actor::query()
            ->where('is_local', true)
            ->where('preferred_username', mb_strtolower($username))
            ->where('status', Actor::STATUS_ACTIVE)
            ->with(['endpoints', 'key', 'user.profile'])
            ->first();
    }

    /**
     * Profilo HTML locale: attivi e sospesi (oscurati). Gli account disabilitati
     * (Actor blocked) restano fuori e il controller risponde 404.
     */
    public function findByUsernameForPublicProfile(string $username): ?Actor
    {
        return Actor::query()
            ->where('is_local', true)
            ->where('preferred_username', mb_strtolower($username))
            ->whereIn('status', [Actor::STATUS_ACTIVE, Actor::STATUS_SUSPENDED])
            ->with(['endpoints', 'key', 'user.profile'])
            ->first();
    }

    public function findByUsernameOrFail(string $username): Actor
    {
        $actor = $this->findByUsername($username);

        abort_if($actor === null, 404);

        return $actor;
    }
}
