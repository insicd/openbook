<?php

namespace App\Http\Controllers;

use App\Application\Services\AccountAliasManager;
use App\Http\Requests\Settings\AccountAliasRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class AccountAliasController extends Controller
{
    public function store(AccountAliasRequest $request, AccountAliasManager $aliases): RedirectResponse
    {
        try {
            $aliases->add($request->user(), $request->validated('alias'));
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('settings.edit', ['tab' => 'migration']));
        }

        return redirect()->route('settings.edit', ['tab' => 'migration'])->with('status', __('openbook.settings.alias_added'));
    }

    public function destroy(AccountAliasRequest $request, AccountAliasManager $aliases): RedirectResponse
    {
        try {
            $aliases->remove($request->user(), $request->validated('alias'));
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('settings.edit', ['tab' => 'migration']));
        }

        return redirect()->route('settings.edit', ['tab' => 'migration'])->with('status', __('openbook.settings.alias_removed'));
    }
}
