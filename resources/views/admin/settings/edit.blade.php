@extends('layouts.admin')

@section('title', __('openbook.admin.settings.title').' - '.config('app.name'))

@section('content')
    <h1>{{ __('openbook.admin.settings.title') }}</h1>
    <p class="ob-field__help">{{ __('openbook.admin.settings.intro') }}</p>

    <form method="POST" action="{{ route('admin.settings.update') }}" class="ob-card" style="margin-top:1rem" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        <div class="ob-field">
            <label for="site_name">{{ __('openbook.admin.settings.site_name') }}</label>
            <input type="text" id="site_name" name="site_name" value="{{ old('site_name', $siteName) }}" required maxlength="100">
        </div>

        <div class="ob-field" style="margin-top:1rem">
            <label for="favicon">{{ __('openbook.admin.settings.favicon') }}</label>
            <div class="ob-settings-avatar-picker">
                <img
                    id="admin-favicon-preview"
                    class="ob-admin-favicon-preview"
                    src="{!! $faviconUrl ? e($faviconUrl) : \App\Application\Services\InstanceSettings::DEFAULT_FAVICON_HREF !!}"
                    alt=""
                    width="48"
                    height="48"
                >
                <input type="file" name="favicon" id="favicon" accept="image/jpeg,image/png,image/webp,image/gif">
            </div>
            <p class="ob-field__help">{{ __('openbook.admin.settings.favicon_help') }}</p>
            @if ($faviconUrl)
                <label class="ob-checkbox" style="margin-top:0.5rem">
                    <input type="checkbox" name="remove_favicon" value="1">
                    {{ __('openbook.admin.settings.favicon_remove') }}
                </label>
            @endif
            @error('favicon')
                <p class="ob-field__error">{{ $message }}</p>
            @enderror
        </div>

        <div class="ob-field" style="margin-top:1rem">
            <label style="display:flex;align-items:center;gap:0.5rem;font-weight:500">
                <input type="checkbox" name="registration_open" value="1" @checked(old('registration_open', $registrationOpen))>
                {{ __('openbook.admin.settings.registration_open') }}
            </label>
            <p class="ob-field__help">{{ __('openbook.admin.settings.registration_help') }}</p>
        </div>

        <div class="ob-field" style="margin-top:1rem">
            <label style="display:flex;align-items:center;gap:0.5rem;font-weight:500">
                <input type="checkbox" name="show_home_staff" value="1" @checked(old('show_home_staff', $showHomeStaff))>
                {{ __('openbook.admin.settings.show_home_staff') }}
            </label>
            <p class="ob-field__help">{{ __('openbook.admin.settings.show_home_staff_help') }}</p>
        </div>

        <div class="ob-field" style="margin-top:1rem">
            <label for="instance_rules">{{ __('openbook.admin.settings.instance_rules') }}</label>
            <textarea id="instance_rules" name="instance_rules" rows="8" maxlength="20000">{{ old('instance_rules', $instanceRules) }}</textarea>
            <p class="ob-field__help">{{ __('openbook.admin.settings.instance_rules_help') }}</p>
        </div>

        <div class="ob-field" style="margin-top:1rem">
            <label for="privacy_policy">{{ __('openbook.admin.settings.privacy_policy') }}</label>
            <textarea id="privacy_policy" name="privacy_policy" rows="8" maxlength="20000">{{ old('privacy_policy', $privacyPolicy) }}</textarea>
            <p class="ob-field__help">{{ __('openbook.admin.settings.privacy_policy_help') }}</p>
        </div>

        <h2 style="margin-top:1.5rem;font-size:1.1rem">{{ __('openbook.admin.settings.limits_title') }}</h2>

        <div class="ob-field" style="margin-top:1rem">
            <label for="post_max_length">{{ __('openbook.admin.settings.post_max_length') }}</label>
            <input type="number" id="post_max_length" name="post_max_length" value="{{ old('post_max_length', $postMaxLength) }}" required min="100" max="50000">
        </div>

        <div class="ob-field" style="margin-top:1rem">
            <label for="comment_max_length">{{ __('openbook.admin.settings.comment_max_length') }}</label>
            <input type="number" id="comment_max_length" name="comment_max_length" value="{{ old('comment_max_length', $commentMaxLength) }}" required min="100" max="10000">
        </div>

        <div class="ob-field" style="margin-top:1rem">
            <label for="media_max_size_kb">{{ __('openbook.admin.settings.media_max_size_kb') }}</label>
            <input type="number" id="media_max_size_kb" name="media_max_size_kb" value="{{ old('media_max_size_kb', $mediaMaxSizeKb) }}" required min="100" max="51200">
        </div>

        <div class="ob-field" style="margin-top:1rem">
            <label for="media_max_attachments">{{ __('openbook.admin.settings.media_max_attachments') }}</label>
            <input type="number" id="media_max_attachments" name="media_max_attachments" value="{{ old('media_max_attachments', $mediaMaxAttachments) }}" required min="1" max="20">
        </div>

        <h2 style="margin-top:1.5rem;font-size:1.1rem">{{ __('openbook.admin.settings.video_title') }}</h2>
        <p class="ob-field__help">{{ __('openbook.admin.settings.video_help') }}</p>

        <div class="ob-field" style="margin-top:1rem">
            <label style="display:flex;align-items:center;gap:0.5rem;font-weight:500">
                <input type="checkbox" name="video_enabled" value="1" @checked(old('video_enabled', $videoEnabled))>
                {{ __('openbook.admin.settings.video_enabled') }}
            </label>
        </div>

        <div class="ob-field" style="margin-top:1rem">
            <label for="video_ffmpeg_path">{{ __('openbook.admin.settings.video_ffmpeg_path') }}</label>
            <input type="text" id="video_ffmpeg_path" name="video_ffmpeg_path" value="{{ old('video_ffmpeg_path', $videoFfmpegPath) }}" required maxlength="1024">
            @error('video_ffmpeg_path')
                <p class="ob-field__error">{{ $message }}</p>
            @enderror
        </div>

        <div class="ob-field" style="margin-top:1rem">
            <label for="video_ffprobe_path">{{ __('openbook.admin.settings.video_ffprobe_path') }}</label>
            <input type="text" id="video_ffprobe_path" name="video_ffprobe_path" value="{{ old('video_ffprobe_path', $videoFfprobePath) }}" required maxlength="1024">
        </div>

        @if ($videoCapability)
            <p class="ob-field__help" style="margin-top:0.75rem">
                @if ($videoCapability['available'])
                    {{ __('openbook.admin.settings.video_tools_available', [
                        'ffmpeg' => $videoCapability['ffmpeg_version'],
                        'ffprobe' => $videoCapability['ffprobe_version'],
                    ]) }}
                @else
                    {{ __('openbook.admin.settings.video_tools_unavailable', ['error' => $videoCapability['error']]) }}
                @endif
            </p>
        @endif

        <div class="ob-field" style="margin-top:1rem">
            <label for="video_max_upload_mb">{{ __('openbook.admin.settings.video_max_upload_mb') }}</label>
            <input type="number" id="video_max_upload_mb" name="video_max_upload_mb" value="{{ old('video_max_upload_mb', $videoLimits['max_upload_mb']) }}" required min="1" max="1024">
        </div>

        <div class="ob-field" style="margin-top:1rem">
            <label for="video_passthrough_max_mb">{{ __('openbook.admin.settings.video_passthrough_max_mb') }}</label>
            <input type="number" id="video_passthrough_max_mb" name="video_passthrough_max_mb" value="{{ old('video_passthrough_max_mb', $videoLimits['passthrough_max_mb']) }}" required min="1" max="1024">
        </div>

        <div class="ob-field" style="margin-top:1rem">
            <label for="video_max_duration_seconds">{{ __('openbook.admin.settings.video_max_duration_seconds') }}</label>
            <input type="number" id="video_max_duration_seconds" name="video_max_duration_seconds" value="{{ old('video_max_duration_seconds', $videoLimits['max_duration_seconds']) }}" required min="1" max="86400">
        </div>

        <div class="ob-field" style="margin-top:1rem">
            <label for="video_max_dimension">{{ __('openbook.admin.settings.video_max_dimension') }}</label>
            <input type="number" id="video_max_dimension" name="video_max_dimension" value="{{ old('video_max_dimension', $videoLimits['max_dimension']) }}" required min="240" max="4320">
        </div>

        <div class="ob-field" style="margin-top:1rem">
            <label for="video_max_frame_rate">{{ __('openbook.admin.settings.video_max_frame_rate') }}</label>
            <input type="number" id="video_max_frame_rate" name="video_max_frame_rate" value="{{ old('video_max_frame_rate', $videoLimits['max_frame_rate']) }}" required min="1" max="120">
        </div>

        <h2 style="margin-top:1.5rem;font-size:1.1rem">{{ __('openbook.admin.settings.trending_title') }}</h2>

        <div class="ob-field" style="margin-top:1rem">
            <label for="trending_days">{{ __('openbook.admin.settings.trending_days') }}</label>
            <input type="number" id="trending_days" name="trending_days" value="{{ old('trending_days', $trendingDays) }}" required min="1" max="365">
            <p class="ob-field__help">{{ __('openbook.admin.settings.trending_days_help') }}</p>
        </div>

        <button type="submit" class="ob-btn ob-btn--primary" style="margin-top:1.25rem">{{ __('openbook.admin.settings.save') }}</button>
    </form>

    <script>
        (function () {
            var input = document.getElementById('favicon');
            var preview = document.getElementById('admin-favicon-preview');

            if (!input || !preview || !window.FileReader) {
                return;
            }

            input.addEventListener('change', function () {
                var file = input.files && input.files[0];

                if (!file || file.type.indexOf('image/') !== 0) {
                    return;
                }

                var reader = new FileReader();
                reader.onload = function (event) {
                    preview.src = event.target.result;
                };
                reader.readAsDataURL(file);
            });
        })();
    </script>
@endsection
