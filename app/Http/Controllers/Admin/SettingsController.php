<?php

namespace App\Http\Controllers\Admin;

use App\Application\Services\InstanceSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class SettingsController extends Controller
{
    public function edit(InstanceSettings $settings): View
    {
        return view('admin.settings.edit', [
            'siteName' => $settings->siteName(),
            'registrationOpen' => $settings->registrationOpen(),
            'instanceRules' => $settings->instanceRules(),
            'privacyPolicy' => $settings->privacyPolicy(),
            'postMaxLength' => $settings->postMaxLength(),
            'commentMaxLength' => $settings->commentMaxLength(),
            'mediaMaxSizeKb' => $settings->mediaMaxSizeKb(),
            'mediaMaxAttachments' => $settings->mediaMaxAttachments(),
        ]);
    }

    public function update(Request $request, InstanceSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'site_name' => ['required', 'string', 'max:100'],
            'registration_open' => ['sometimes', 'boolean'],
            'instance_rules' => ['nullable', 'string', 'max:20000'],
            'privacy_policy' => ['nullable', 'string', 'max:20000'],
            'post_max_length' => ['required', 'integer', 'min:100', 'max:50000'],
            'comment_max_length' => ['required', 'integer', 'min:100', 'max:10000'],
            'media_max_size_kb' => ['required', 'integer', 'min:100', 'max:51200'],
            'media_max_attachments' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        $settings->update([
            'site_name' => $data['site_name'],
            'registration_open' => $request->boolean('registration_open'),
            'instance_rules' => $data['instance_rules'] ?? '',
            'privacy_policy' => $data['privacy_policy'] ?? '',
            'post_max_length' => (int) $data['post_max_length'],
            'comment_max_length' => (int) $data['comment_max_length'],
            'media_max_size_kb' => (int) $data['media_max_size_kb'],
            'media_max_attachments' => (int) $data['media_max_attachments'],
        ], $request->user());

        return redirect()
            ->route('admin.settings.edit')
            ->with('status', __('openbook.admin.settings.saved'));
    }
}
