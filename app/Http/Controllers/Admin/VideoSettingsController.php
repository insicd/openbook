<?php

namespace App\Http\Controllers\Admin;

use App\Application\Services\InstanceSettings;
use App\Http\Controllers\Controller;
use App\Infrastructure\Media\VideoCapability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class VideoSettingsController extends Controller
{
    public function edit(InstanceSettings $settings): View
    {
        $videoEnabled = $settings->videoEnabled();

        return view('admin.video.edit', [
            'videoEnabled' => $videoEnabled,
            'videoFfmpegPath' => $settings->videoFfmpegPath(),
            'videoFfprobePath' => $settings->videoFfprobePath(),
            'videoLimits' => $settings->videoLimits(),
            'videoCapability' => $videoEnabled
                ? app(VideoCapability::class)->inspect($settings->videoFfmpegPath(), $settings->videoFfprobePath())
                : null,
        ]);
    }

    public function update(Request $request, InstanceSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'video_enabled' => ['sometimes', 'boolean'],
            'video_ffmpeg_path' => ['required', 'string', 'max:1024'],
            'video_ffprobe_path' => ['required', 'string', 'max:1024'],
            'video_max_upload_mb' => ['required', 'integer', 'min:1', 'max:1024'],
            'video_passthrough_max_mb' => ['required', 'integer', 'min:1', 'lte:video_max_upload_mb'],
            'video_max_duration_seconds' => ['required', 'integer', 'min:1', 'max:86400'],
            'video_max_dimension' => ['required', 'integer', 'min:240', 'max:4320'],
            'video_max_frame_rate' => ['required', 'integer', 'min:1', 'max:120'],
        ]);
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

        $data['video_enabled'] = $videoEnabled;
        DB::transaction(fn () => $settings->update($data, $request->user()));

        return redirect()->route('admin.video.edit')->with('status', __('openbook.admin.settings.saved'));
    }
}
