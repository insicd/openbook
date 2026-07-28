<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applica la lingua scelta dall'utente autenticato (impostazioni account)
 * per l'intera richiesta. Chi non ha effettuato l'accesso vede sempre la
 * lingua di default dell'istanza ("app.locale"): Openbook non deduce la
 * lingua dal browser per restare semplice e prevedibile.
 */
class SetUserLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->settings?->locale;

        if (is_string($locale) && array_key_exists($locale, (array) config('openbook.locales'))) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
