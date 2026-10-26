<?php

namespace App\Providers;

use App\Infrastructure\Auth\ActiveAccountTokenRepository;
use App\Infrastructure\Auth\LockingRefreshTokenRepository;
use DateInterval;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Passport;

final class RestAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Passport::ignoreRoutes();
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
