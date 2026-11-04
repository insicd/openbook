<?php

namespace App\Http\Controllers\Admin;

use App\Application\Services\InstanceSettings;
use App\Domain\Accounts\User;
use App\Http\Controllers\Controller;
use App\Infrastructure\Media\HomeBackgroundUploader;
use App\Infrastructure\Media\InstanceIconUploader;
use App\Infrastructure\Media\MediaStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

final class SettingsController extends Controller
{
    private const TABS = ['general', 'registration', 'content'];

    public function edit(Request $request, InstanceSettings $settings): View
    {
        $activeTab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'general';

        return view('admin.settings.edit', [
            'activeTab' => $activeTab,
            'tabs' => array_map(fn ($tab) => ['label' => __('openbook.admin.settings.tab_'.$tab), 'url' => route('admin.settings.edit', ['tab' => $tab]), 'active' => $tab === $activeTab], self::TABS),
            'siteName' => $settings->siteName(),
            'siteDescription' => $settings->siteDescription(),
            'contactEmail' => $settings->contactEmail(),
            'contactAccountId' => $settings->contactAccountId(),
            'contactAccounts' => User::query()->where('status', User::STATUS_ACTIVE)
                ->where('is_admin', true)->whereHas('actor')->orderBy('username')->get(),
            'registrationOpen' => $settings->registrationOpen(),
            'showHomeStaff' => $settings->showHomeStaff(),
            'instanceRules' => $settings->instanceRules(),
            'privacyPolicy' => $settings->privacyPolicy(),
            'postMaxLength' => $settings->postMaxLength(),
            'commentMaxLength' => $settings->commentMaxLength(),
            'mediaMaxSizeKb' => $settings->mediaMaxSizeKb(),
            'mediaMaxAttachments' => $settings->mediaMaxAttachments(),
            'trendingDays' => $settings->trendingDays(),
            'faviconUrl' => $settings->faviconUrl(),
            'homeBackgroundUrl' => $settings->homeBackgroundUrl(),
        ]);
    }

    public function update(
        Request $request,
        InstanceSettings $settings,
        InstanceIconUploader $iconUploader,
        HomeBackgroundUploader $backgroundUploader,
    ): RedirectResponse {
        $maxKb = (int) config('openbook.media.max_size_kb');

        $tab = in_array($request->input('tab'), self::TABS, true) ? $request->input('tab') : 'general';
        $fields = [
            'general' => ['site_name', 'site_description', 'contact_email', 'contact_account_id', 'show_home_staff', 'favicon', 'remove_favicon', 'home_background', 'remove_home_background'],
            'registration' => ['registration_open', 'instance_rules', 'privacy_policy'],
            'content' => ['post_max_length', 'comment_max_length', 'media_max_size_kb', 'media_max_attachments', 'trending_days'],
        ];
        $rules = [
            'site_name' => ['required', 'string', 'max:100'],
            'site_description' => ['nullable', 'string', 'max:500'],
            'contact_email' => ['nullable', 'email', 'max:254'],
            'contact_account_id' => ['nullable', 'uuid', Rule::exists('users', 'id')
                ->where('status', User::STATUS_ACTIVE)->where('is_admin', true)],
            'registration_open' => ['sometimes', 'boolean'],
            'show_home_staff' => ['sometimes', 'boolean'],
            'instance_rules' => ['nullable', 'string', 'max:20000'],
            'privacy_policy' => ['nullable', 'string', 'max:20000'],
            'post_max_length' => ['required', 'integer', 'min:100', 'max:50000'],
            'comment_max_length' => ['required', 'integer', 'min:100', 'max:10000'],
            'media_max_size_kb' => ['required', 'integer', 'min:100', 'max:51200'],
            'media_max_attachments' => ['required', 'integer', 'min:1', 'max:20'],
            'trending_days' => ['required', 'integer', 'min:1', 'max:365'],
            'favicon' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:'.$maxKb],
            'remove_favicon' => ['sometimes', 'boolean'],
            'home_background' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:'.max($maxKb, 8192)],
            'remove_home_background' => ['sometimes', 'boolean'],
        ];
        $data = $request->validate(Arr::only($rules, $fields[$tab]), [], [
            'favicon' => __('openbook.admin.settings.favicon'),
            'home_background' => __('openbook.admin.settings.home_background'),
        ]);

        if ($tab === 'registration') {
            $data['registration_open'] = $request->boolean('registration_open');
        }
        if ($tab !== 'general') {
            DB::transaction(fn () => $settings->update($data, $request->user()));

            return redirect()->route('admin.settings.edit', ['tab' => $tab])->with('status', __('openbook.admin.settings.saved'));
        }
        $data['show_home_staff'] = $request->boolean('show_home_staff');
        $iconDirectory = $settings->iconDirectory();
        $backgroundDirectory = $settings->homeBackgroundDirectory();

        DB::transaction(function () use ($request, $settings, $iconUploader, $backgroundUploader, $data, $iconDirectory, $backgroundDirectory): void {
            $iconDisk = $settings->iconDisk();
            $backgroundDisk = $settings->homeBackgroundDisk();
            $destination = app(MediaStorage::class)->uploadDisk();
            try {
                if ($request->hasFile('favicon')) {
                    $iconDirectory = $iconUploader->store($request->file('favicon'), $iconDirectory, $iconDisk, $destination);
                    $iconDisk = $destination;
                } elseif ($request->boolean('remove_favicon')) {
                    $iconUploader->deleteDirectory($iconDirectory, $iconDisk);
                    $iconDirectory = null;
                }
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'favicon' => $exception->getMessage(),
                ]);
            }

            try {
                if ($request->hasFile('home_background')) {
                    $backgroundDirectory = $backgroundUploader->store($request->file('home_background'), $backgroundDirectory, $backgroundDisk, $destination);
                    $backgroundDisk = $destination;
                } elseif ($request->boolean('remove_home_background')) {
                    $backgroundUploader->deleteDirectory($backgroundDirectory, $backgroundDisk);
                    $backgroundDirectory = null;
                }
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'home_background' => $exception->getMessage(),
                ]);
            }

            unset($data['favicon'], $data['home_background'], $data['remove_favicon'], $data['remove_home_background']);
            $settings->update($data + [
                'instance_icon_dir' => $iconDirectory, 'instance_icon_disk' => $iconDisk,
                'home_background_dir' => $backgroundDirectory, 'home_background_disk' => $backgroundDisk,
            ], $request->user());
        });

        return redirect()
            ->route('admin.settings.edit')
            ->with('status', __('openbook.admin.settings.saved'));
    }
}
