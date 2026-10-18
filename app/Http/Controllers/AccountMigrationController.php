<?php

namespace App\Http\Controllers;

use App\Application\Services\AccountMigrationService;
use App\Http\Requests\Settings\AccountMigrationRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AccountMigrationController extends Controller
{
    public function preview(AccountMigrationRequest $request, AccountMigrationService $migrations): View
    {
        return view('settings.migration_confirm', [
            'destination' => $migrations->verify($request->user(), $request->validated('destination')),
        ]);
    }

    public function store(AccountMigrationRequest $request, AccountMigrationService $migrations): RedirectResponse
    {
        try {
            $migrations->start($request->user(), $request->validated('destination'));
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('settings.edit'));
        }

        return redirect()->route('settings.edit')->with('status', __('openbook.settings.migration_started'));
    }

    public function destroy(Request $request, AccountMigrationService $migrations): RedirectResponse
    {
        $migrations->removeIndication($request->user());

        return redirect()->route('settings.edit')->with('status', __('openbook.settings.migration_removed'));
    }
}
