<?php

namespace App\Http\Controllers;

use App\Application\Services\ConnectedApplications;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ConnectedApplicationController extends Controller
{
    public function destroy(Request $request, string $access, ConnectedApplications $applications): RedirectResponse
    {
        $closed = $applications->close($request->user(), $access);

        return redirect()->route('settings.edit', ['tab' => 'applications'])
            ->with('status', __($closed ? 'oauth.closed' : 'oauth.list_changed'));
    }
}
