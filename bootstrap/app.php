<?php

use App\Http\Middleware\EnsureApplicationIsInstalled;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')->group(base_path('routes/install.php'));

            // Nessun gruppo "web": gli endpoint di federazione sono stateless
            // (niente sessione, niente CSRF) e vengono consumati da altri
            // server, non da browser.
            Route::group([], base_path('routes/activitypub.php'));

            Route::group([], base_path('routes/cron.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            EnsureApplicationIsInstalled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();

Authenticate::redirectUsing(fn () => route('login'));
