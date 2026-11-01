<?php

namespace App\Providers;

use App\Infrastructure\Auth\ActiveAccountTokenRepository;
use App\Infrastructure\Auth\LockingRefreshTokenRepository;
use App\Infrastructure\Auth\OAuthSigningKeys;
use App\Infrastructure\Installation\InstallationLock;
use DateInterval;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\ResourceServer;

final class RestAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Passport::ignoreRoutes();
        $this->app->singleton(OAuthSigningKeys::class);
        foreach ([AuthorizationServer::class, ResourceServer::class] as $server) {
            $this->app->beforeResolving($server, function (): void {
                abort_unless(InstallationLock::isInstalled(), 503);
                $keys = $this->app->make(OAuthSigningKeys::class)->getOrCreate();
                $this->app['config']->set([
                    'passport.private_key' => $keys->privateKey,
                    'passport.public_key' => $keys->publicKey,
                ]);
            });
        }
        $this->app->bind(AccessTokenRepository::class, ActiveAccountTokenRepository::class);
        $this->app->bind(RefreshTokenRepository::class, LockingRefreshTokenRepository::class);
    }

    public function boot(): void
    {
        Passport::authorizationView('auth.oauth.authorize');
        Passport::tokensCan(['api' => 'Use OpenBook user features.']);
        Passport::setDefaultScope(['api']);
        $duration = config('openbook.oauth_token_duration', 365);
        $days = (is_int($duration) || is_string($duration))
            ? filter_var($duration, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        $days = $days ?: 365;
        Passport::tokensExpireIn(new DateInterval('P'.$days.'D'));
        Passport::refreshTokensExpireIn(new DateInterval('P'.$days.'D'));
    }
}
