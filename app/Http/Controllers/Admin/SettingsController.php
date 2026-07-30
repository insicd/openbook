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
            'instanceRules' => $this->instanceSettings->instanceRules(),
            'postMaxLength' => $this->instanceSettings->postMaxLength(),
            'commentMaxLength' => $this->instanceSettings->commentMaxLength(),
            'mediaMaxSizeKb' => $this->instanceSettings->mediaMaxSizeKb(),
            'mediaMaxAttachments' => $this->instanceSettings->mediaMaxAttachments(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'site_name' => ['required', 'string', 'max:100'],
            'registration_open' => ['sometimes', 'boolean'],
            'instance_rules' => ['nullable', 'string', 'max:20000'],
            'post_max_length' => ['required', 'integer', 'min:100', 'max:50000'],
            'comment_max_length' => ['required', 'integer', 'min:100', 'max:10000'],
            'media_max_size_kb' => ['required', 'integer', 'min:100', 'max:51200'],
            'media_max_attachments' => ['required', 'integer', 'min:1', 'max:20'],
        ], [], [
            'site_name' => __('openbook.admin.settings.site_name'),
            'registration_open' => __('openbook.admin.settings.registration_open'),
            'instance_rules' => __('openbook.admin.settings.instance_rules'),
            'post_max_length' => __('openbook.admin.settings.post_max_length'),
            'comment_max_length' => __('openbook.admin.settings.comment_max_length'),
            'media_max_size_kb' => __('openbook.admin.settings.media_max_size_kb'),
            'media_max_attachments' => __('openbook.admin.settings.media_max_attachments'),
        ]);

        $this->instanceSettings->update([
            'site_name' => $data['site_name'],
            'registration_open' => $request->boolean('registration_open'),
            'instance_rules' => $data['instance_rules'] ?? '',
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
