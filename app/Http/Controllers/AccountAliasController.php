<?php

namespace App\Http\Controllers;

use App\Application\Services\AccountAliasManager;
use App\Http\Requests\Settings\AccountAliasRequest;
use Illuminate\Http\RedirectResponse;

class AccountAliasController extends Controller
{
    public function store(AccountAliasRequest $request, AccountAliasManager $aliases): RedirectResponse
    {
        $aliases->add($request->user(), $request->validated('alias'));

        return redirect()->route('settings.edit')->with('status', __('openbook.settings.alias_added'));
    }

    public function destroy(AccountAliasRequest $request, AccountAliasManager $aliases): RedirectResponse
    {
        $aliases->remove($request->user(), $request->validated('alias'));

        return redirect()->route('settings.edit')->with('status', __('openbook.settings.alias_removed'));
    }
}
