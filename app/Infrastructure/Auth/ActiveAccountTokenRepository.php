<?php

namespace App\Infrastructure\Auth;

use App\Domain\Accounts\User;
use Laravel\Passport\Bridge\AccessTokenRepository;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;

final class ActiveAccountTokenRepository extends AccessTokenRepository
{
    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        // An authorization code or refresh token can outlive an active account.
        $user = User::query()->with('actor')->find($accessTokenEntity->getUserIdentifier());
        if (! $user?->isActive() || ! $user->actor?->isActive()) {
            throw OAuthServerException::accessDenied('The account is not available.');
        }

        parent::persistNewAccessToken($accessTokenEntity);
    }
}
