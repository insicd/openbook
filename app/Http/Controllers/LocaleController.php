<?php

namespace App\Http\Controllers;

use App\Application\Services\AccountPreferencesUpdater;
use App\Http\Requests\UpdateLocaleRequest;
use App\Support\InterfaceLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cookie;

class LocaleController extends Controller
{
    public function update(UpdateLocaleRequest $request, AccountPreferencesUpdater $preferences): RedirectResponse
    {
        $locale = $request->validated('locale');
        if ($request->user() !== null) {
            $preferences->updateLocale($request->user(), $locale);
        }

        return redirect($request->validated('return_to', '/'))
            ->cookie(Cookie::make(InterfaceLocale::COOKIE_NAME, $locale, InterfaceLocale::COOKIE_MINUTES,
                '/', null, $request->isSecure(), true, false, 'lax'));
    }
}
