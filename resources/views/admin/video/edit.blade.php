@extends('layouts.admin')
@section('title', __('openbook.admin.nav.video').' - '.config('app.name'))
@section('content')
    <h1>{{ __('openbook.admin.nav.video') }}</h1>
    <form method="POST" action="{{ route('admin.video.update') }}" class="ob-card" style="margin-top:1rem">
        @csrf
        @method('PUT')
        <h2 style="margin-top:1.5rem;font-size:1.1rem">{{ __('openbook.admin.settings.video_title') }}</h2>
        <p class="ob-field__help">{{ __('openbook.admin.settings.video_help') }}</p>

        <div class="ob-field" style="margin-top:1rem">
            <label style="display:flex;align-items:center;gap:0.5rem;font-weight:500">
                <input type="hidden" name="video_enabled" value="0">
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

        <button type="submit" class="ob-btn ob-btn--primary" style="margin-top:1.25rem">{{ __('openbook.admin.settings.save') }}</button>
    </form>
@endsection
