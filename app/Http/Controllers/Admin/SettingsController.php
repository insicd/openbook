<?php

namespace App\Http\Controllers\Admin;

use App\Application\Services\InstanceSettings;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(
        private readonly InstanceSettings $instanceSettings,
    ) {}

    public function edit(): View
    {
        return view('admin.settings.edit', [
            'siteName' => $this->instanceSettings->siteName(),
            'registrationOpen' => $this->instanceSettings->registrationOpen(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'site_name' => ['required', 'string', 'max:100'],
            'registration_open' => ['sometimes', 'boolean'],
        ], [], [
            'site_name' => __('openbook.admin.settings.site_name'),
            'registration_open' => __('openbook.admin.settings.registration_open'),
        ]);

        $this->instanceSettings->update([
            'site_name' => $data['site_name'],
            'registration_open' => $request->boolean('registration_open'),
        ]);

        return redirect()
            ->route('admin.settings.edit')
            ->with('status', __('openbook.admin.settings.saved'));
    }
}
