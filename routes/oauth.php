<?php

use App\Http\Controllers\Auth\OAuthClientController;
use App\Http\Middleware\EnsureRestOAuthRequest;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;

Route::middleware(EnsureRestOAuthRequest::class)->group(function () {
    Route::get('/.well-known/oauth-authorization-server', [OAuthClientController::class, 'metadata'])->name('oauth.metadata');
    Route::post('/oauth/register', [OAuthClientController::class, 'register'])->middleware('throttle:10,1,oauth-register:')->name('oauth.register');
    Route::post('/oauth/token', [AccessTokenController::class, 'issueToken'])->middleware('throttle:30,1,oauth-token:')->name('passport.token');
});

Route::middleware(['web', EnsureRestOAuthRequest::class])->group(function () {
    Route::get('/oauth/authorize', [AuthorizationController::class, 'authorize'])->name('passport.authorizations.authorize');
    Route::middleware('auth:web')->group(function () {
        Route::post('/oauth/authorize', [ApproveAuthorizationController::class, 'approve'])->name('passport.authorizations.approve');
        Route::delete('/oauth/authorize', [DenyAuthorizationController::class, 'deny'])->name('passport.authorizations.deny');
    });
});
