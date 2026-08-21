<?php

namespace App\Http\Controllers;

use App\Application\Services\AccountPreferencesUpdater;
use App\Application\Services\ProfileUpdater;
use App\Http\Requests\Settings\UpdateAccountRequest;
use App\Http\Requests\Settings\UpdateProfileRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Pagina "Impostazioni" dell'account autenticato: personalizzazione del
 * profilo pubblico (nome, biografia, link, avatar, copertina) e preferenze
 * personali (lingua dell'interfaccia, visibilita' predefinita dei nuovi
 * post, approvazione manuale dei follower, discoverable/indexable).
 */
class SettingsController extends Controller
{
    public function __construct(
        private readonly ProfileUpdater $profileUpdater,
        private readonly AccountPreferencesUpdater $accountPreferencesUpdater,
    ) {}

    public function edit(): View
    {
        $user = auth()->user();
        $user->loadMissing(['profile', 'settings', 'actor']);

        return view('settings.index', ['viewer' => $user]);
    }

    public function updateProfile(UpdateProfileRequest $request): RedirectResponse
    {
        $this->profileUpdater->update(
            $request->user(),
            $request->safe()->only(['display_name', 'bio', 'links']),
            $request->file('avatar'),
            $request->file('cover'),
        );

        return redirect()->route('settings.edit')->with('status', __('openbook.settings.profile_updated'));
    }

    public function updateAccount(UpdateAccountRequest $request): RedirectResponse
    {
        $this->accountPreferencesUpdater->update($request->user(), $request->validated());

        return redirect()->route('settings.edit')->with('status', __('openbook.settings.account_updated'));
    }
}
