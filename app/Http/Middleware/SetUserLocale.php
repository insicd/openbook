<?php

namespace App\Http\Middleware;

use App\Support\InterfaceLocale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/** Lingua account, preferenza dell'ospite, browser e default dell'istanza. */
class SetUserLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $locale = $user !== null
            ? $user->settings?->locale
            : $this->guestLocale($request);

        if (InterfaceLocale::isSupported($locale)) {
            App::setLocale($locale);
        }

        return $next($request);
    }

    private function guestLocale(Request $request): ?string
    {
        $preferred = $request->cookie(InterfaceLocale::COOKIE_NAME);
        if (InterfaceLocale::isSupported($preferred)) {
            return $preferred;
        }

        if ($request->getLanguages() === []) {
            return null;
        }

        $locales = array_keys((array) config('openbook.locales'));
        // Preserve the English fallback for browsers without a matching language.
        if (in_array('en', $locales, true)) {
            $locales = array_values(array_unique(['en', ...$locales]));
        }

        return $locales === [] ? null : $request->getPreferredLanguage($locales);
    }
}
