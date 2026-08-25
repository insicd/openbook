<?php

namespace App\Application\Services;

use App\Federation\Actors\Actor;

/**
 * Profilo da condividere in un messaggio privato: Person attivo (locale o
 * remoto in cache). Il destinatario riceve il link alla pagina Openbook
 * ({@see Actor::profileUrl()}), non l'URI ActivityPub originale.
 */
final class QuotedActorResolver
{
    public function resolveForShare(?Actor $viewer, ?string $quotedActorId): ?Actor
    {
        if ($viewer === null || $quotedActorId === null || $quotedActorId === '') {
            return null;
        }

        $actor = Actor::query()
            ->with(['user.profile'])
            ->whereKey($quotedActorId)
            ->first();

        if ($actor === null || ! $actor->isPerson() || ! $actor->isActive()) {
            return null;
        }

        return $actor;
    }
}
