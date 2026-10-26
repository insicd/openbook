<?php

namespace App\Infrastructure\Auth;

use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Passport;

final class LockingRefreshTokenRepository extends RefreshTokenRepository
{
    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        $accessId = Passport::refreshToken()->newQuery()->whereKey($tokenId)->value('access_token_id');
        if ($accessId === null) {
            return true;
        }

        // Use the same lock order as closing an access. Keep both locks until
        // the token endpoint completes, so renewal cannot bypass a concurrent close.
        Passport::token()->newQuery()->whereKey($accessId)->lockForUpdate()->first(['id']);
        $refresh = Passport::refreshToken()->newQuery()->whereKey($tokenId)->lockForUpdate()->first();

        return $refresh === null || $refresh->revoked;
    }
}
