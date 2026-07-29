<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sceglie la lingua dell'intera richiesta.
 *
 * - Un utente autenticato vede sempre la lingua scelta esplicitamente nelle
 *   proprie impostazioni account, qualunque sia il browser che sta usando in
 *   quel momento.
 * - Un ospite (non autenticato, quindi senza alcuna preferenza salvata) vede
 *   invece la lingua dedotta dall'header "Accept-Language" del browser, cosi'
 *   la homepage pubblica si presenta gia' nella lingua giusta prima ancora
 *   della registrazione. Per restare semplice e prevedibile la deduzione e'
 *   binaria (vedi "guestLocale()"): italiano se il browser lo preferisce,
 *   inglese in ogni altro caso (una terza lingua non ancora supportata
 *   dall'istanza). Una richiesta senza alcun "Accept-Language" (praticamente
 *   mai un browser reale: tipico invece di crawler/monitoraggi automatici)
 *   non viene forzata ne' sull'uno ne' sull'altro: resta la lingua di default
 *   dell'istanza ("app.locale").
 */
class SetUserLocale
{
    /**
     * Ordine deliberatamente diverso da quello "di visualizzazione" di
     * "openbook.locales" (che in config/openbook.php elenca l'italiano per
     * primo solo per un discorso di leggibilita' del form impostazioni):
     * qui il primo elemento e' anche il valore di fallback quando il browser
     * non dichiara nessuna preferenza riconoscibile (vedi
     * Request::getPreferredLanguage(), che ritorna sempre $locales[0] in
     * assenza di corrispondenze), e deve essere l'inglese per rispettare la
     * regola "italiano se il browser lo preferisce, altrimenti inglese".
     */
    private const GUEST_LOCALE_PRIORITY = ['en', 'it'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $locale = $user !== null
            ? $user->settings?->locale
            : $this->guestLocale($request);

        if (is_string($locale) && array_key_exists($locale, (array) config('openbook.locales'))) {
            App::setLocale($locale);
        }

        return $next($request);
    }

    private function guestLocale(Request $request): ?string
    {
        if ($request->getLanguages() === []) {
            return null;
        }

        return $request->getPreferredLanguage(self::GUEST_LOCALE_PRIORITY);
    }
}
