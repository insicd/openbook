<?php

namespace App\Federation\Fetch;

use App\Federation\Actors\Actor;
use Illuminate\Support\Facades\Auth;

/**
 * Sceglie l'Actor locale con cui firmare i GET ActivityPub (authorized fetch).
 *
 * Preferenza: Actor esplicitamente passato, poi l'utente autenticato, poi
 * un qualunque Actor locale attivo con chiave privata (admin se possibile).
 * Senza Actor firmabile i fetch restano anonimi (compatibili solo con
 * server che non richiedono Signature sui GET).
 */
final class FederationFetchSigner
{
    public function resolve(?Actor $preferred = null): ?Actor
    {
        if (! (bool) config('openbook.federation.fetch.signed', true)) {
            return null;
        }

        if ($preferred !== null) {
            $preferred->loadMissing('key');

            if ($this->canSign($preferred)) {
                return $preferred;
            }
        }

        $viewer = Auth::user()?->actor;

        if ($viewer !== null) {
            $viewer->loadMissing('key');

            if ($this->canSign($viewer)) {
                return $viewer;
            }
        }

        return $this->fallbackLocalActor();
    }

    private function canSign(Actor $actor): bool
    {
        return $actor->isLocal()
            && $actor->status === Actor::STATUS_ACTIVE
            && $actor->key !== null
            && $actor->key->hasPrivateKey();
    }

    private function fallbackLocalActor(): ?Actor
    {
        $admin = Actor::query()
            ->where('is_local', true)
            ->where('status', Actor::STATUS_ACTIVE)
            ->whereHas('key')
            ->whereHas('user', static fn ($query) => $query->where('is_admin', true))
            ->with('key')
            ->orderBy('created_at')
            ->first();

        if ($admin !== null && $this->canSign($admin)) {
            return $admin;
        }

        $actor = Actor::query()
            ->where('is_local', true)
            ->where('status', Actor::STATUS_ACTIVE)
            ->whereHas('key')
            ->with('key')
            ->orderBy('created_at')
            ->first();

        return ($actor !== null && $this->canSign($actor)) ? $actor : null;
    }
}
