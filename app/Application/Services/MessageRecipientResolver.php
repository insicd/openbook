<?php

namespace App\Application\Services;

use App\Domain\Accounts\User;
use App\Federation\Actors\Actor;

/**
 * Risolve un handle grezzo (username locale o user@dominio remoto) in Actor.
 */
final class MessageRecipientResolver
{
    public function resolve(string $raw, ?Actor $viewer = null): ?Actor
    {
        $raw = trim(ltrim($raw, '@'));

        if ($raw === '') {
            return null;
        }

        if (str_contains($raw, '@')) {
            [$username, $domain] = explode('@', $raw, 2);
            $username = mb_strtolower(trim($username));
            $domain = mb_strtolower(trim($domain));

            if ($username === '' || $domain === '' || preg_match('/^[a-z0-9_]{1,32}$/', $username) !== 1) {
                return null;
            }

            $actor = Actor::query()
                ->where('type', Actor::TYPE_PERSON)
                ->where('status', Actor::STATUS_ACTIVE)
                ->where('preferred_username', $username)
                ->where('domain', $domain)
                ->first();
        } else {
            $username = mb_strtolower($raw);

            if (preg_match('/^[a-z0-9_]{1,32}$/', $username) !== 1) {
                return null;
            }

            $user = User::query()->where('username', $username)->with('actor')->first();
            $actor = $user?->actor;

            if ($actor === null || ! $actor->isPerson() || ! $actor->isActive()) {
                return null;
            }
        }

        if ($viewer !== null && $actor->id === $viewer->id) {
            return null;
        }

        return $actor;
    }
}
