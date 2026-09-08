<?php

namespace App\Http\Controllers\Admin;

use App\Application\Services\InstanceSettings;
use App\Http\Controllers\Controller;
use App\Infrastructure\Media\InstanceIconUploader;
use App\Infrastructure\Media\VideoCapability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

final class SettingsController extends Controller
{
    public function edit(InstanceSettings $settings): View
    {
        $videoEnabled = $settings->videoEnabled();

        return view('admin.settings.edit', [
            'siteName' => $settings->siteName(),
            'registrationOpen' => $settings->registrationOpen(),
            'showHomeStaff' => $settings->showHomeStaff(),
            'instanceRules' => $settings->instanceRules(),
            'privacyPolicy' => $settings->privacyPolicy(),
            'postMaxLength' => $settings->postMaxLength(),
            'commentMaxLength' => $settings->commentMaxLength(),
            'mediaMaxSizeKb' => $settings->mediaMaxSizeKb(),
            'mediaMaxAttachments' => $settings->mediaMaxAttachments(),
            'videoEnabled' => $videoEnabled,
            'videoFfmpegPath' => $settings->videoFfmpegPath(),
            'videoFfprobePath' => $settings->videoFfprobePath(),
            'videoLimits' => $settings->videoLimits(),
            'videoCapability' => $videoEnabled
                ? app(VideoCapability::class)->inspect($settings->videoFfmpegPath(), $settings->videoFfprobePath())
                : null,
            'trendingDays' => $settings->trendingDays(),
            'faviconUrl' => $settings->faviconUrl(),
        ]);
    }

    public function update(
        Request $request,
        InstanceSettings $settings,
        InstanceIconUploader $iconUploader,
    ): RedirectResponse {
        $maxKb = (int) config('openbook.media.max_size_kb');

        $data = $request->validate([
            'site_name' => ['required', 'string', 'max:100'],
            'registration_open' => ['sometimes', 'boolean'],
            'show_home_staff' => ['sometimes', 'boolean'],
            'instance_rules' => ['nullable', 'string', 'max:20000'],
            'privacy_policy' => ['nullable', 'string', 'max:20000'],
            'post_max_length' => ['required', 'integer', 'min:100', 'max:50000'],
            'comment_max_length' => ['required', 'integer', 'min:100', 'max:10000'],
            'media_max_size_kb' => ['required', 'integer', 'min:100', 'max:51200'],
            'media_max_attachments' => ['required', 'integer', 'min:1', 'max:20'],
            'video_enabled' => ['sometimes', 'boolean'],
            'video_ffmpeg_path' => ['required', 'string', 'max:1024'],
            'video_ffprobe_path' => ['required', 'string', 'max:1024'],
            'video_max_upload_mb' => ['required', 'integer', 'min:1', 'max:1024'],
            'video_passthrough_max_mb' => ['required', 'integer', 'min:1', 'lte:video_max_upload_mb'],
            'video_max_duration_seconds' => ['required', 'integer', 'min:1', 'max:86400'],
            'video_max_dimension' => ['required', 'integer', 'min:240', 'max:4320'],
            'video_max_frame_rate' => ['required', 'integer', 'min:1', 'max:120'],
            'trending_days' => ['required', 'integer', 'min:1', 'max:365'],
            'favicon' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:'.$maxKb],
            'remove_favicon' => ['sometimes', 'boolean'],
        ], [], [
            'favicon' => __('openbook.admin.settings.favicon'),
        ]);

        $iconDirectory = $settings->iconDirectory();
        $videoEnabled = $request->boolean('video_enabled');

        if ($videoEnabled) {
            $capability = app(VideoCapability::class)->inspect($data['video_ffmpeg_path'], $data['video_ffprobe_path']);

            if (! $capability['available']) {
                throw ValidationException::withMessages([
                    'video_ffmpeg_path' => __('openbook.admin.settings.video_tools_unavailable', [
                        'error' => $capability['error'],
                    ]),
                ]);
            }
        }

        try {
            if ($request->hasFile('favicon')) {
                $iconDirectory = $iconUploader->store($request->file('favicon'), $iconDirectory);
            } elseif ($request->boolean('remove_favicon')) {
                $iconUploader->deleteDirectory($iconDirectory);
                $iconDirectory = null;
            }
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'favicon' => $exception->getMessage(),
            ]);
        }

        $settings->update([
            'site_name' => $data['site_name'],
            'registration_open' => $request->boolean('registration_open'),
            'show_home_staff' => $request->boolean('show_home_staff'),
            'instance_rules' => $data['instance_rules'] ?? '',
            'privacy_policy' => $data['privacy_policy'] ?? '',
            'post_max_length' => (int) $data['post_max_length'],
            'comment_max_length' => (int) $data['comment_max_length'],
            'media_max_size_kb' => (int) $data['media_max_size_kb'],
            'media_max_attachments' => (int) $data['media_max_attachments'],
            'video_enabled' => $videoEnabled,
            'video_ffmpeg_path' => $data['video_ffmpeg_path'],
            'video_ffprobe_path' => $data['video_ffprobe_path'],
            'video_max_upload_mb' => (int) $data['video_max_upload_mb'],
            'video_passthrough_max_mb' => (int) $data['video_passthrough_max_mb'],
            'video_max_duration_seconds' => (int) $data['video_max_duration_seconds'],
            'video_max_dimension' => (int) $data['video_max_dimension'],
            'video_max_frame_rate' => (int) $data['video_max_frame_rate'],
            'trending_days' => (int) $data['trending_days'],
            'instance_icon_dir' => $iconDirectory,
        ], $request->user());

        return redirect()
            ->route('admin.settings.edit')
            ->with('status', __('openbook.admin.settings.saved'));
    }
}
