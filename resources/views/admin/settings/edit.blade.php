@extends('layouts.admin')

@section('title', __('openbook.admin.settings.title').' - '.config('app.name'))

@section('content')
    <h1>{{ __('openbook.admin.settings.title') }}</h1>
    <p class="ob-field__help">{{ __('openbook.admin.settings.intro') }}</p>

    <form method="POST" action="{{ route('admin.settings.update') }}" class="ob-card" style="margin-top:1rem">
        @csrf
        @method('PUT')

        <div class="ob-field">
            <label for="site_name">{{ __('openbook.admin.settings.site_name') }}</label>
            <input type="text" id="site_name" name="site_name" value="{{ old('site_name', $siteName) }}" required maxlength="100">
        </div>

        <div class="ob-field" style="margin-top:1rem">
            <label style="display:flex;align-items:center;gap:0.5rem;font-weight:500">
                <input type="checkbox" name="registration_open" value="1" @checked(old('registration_open', $registrationOpen))>
                {{ __('openbook.admin.settings.registration_open') }}
            </label>
            <p class="ob-field__help">{{ __('openbook.admin.settings.registration_help') }}</p>
        </div>

        <div class="ob-field" style="margin-top:1rem">
            <label for="instance_rules">{{ __('openbook.admin.settings.instance_rules') }}</label>
            <textarea id="instance_rules" name="instance_rules" rows="8" maxlength="20000">{{ old('instance_rules', $instanceRules) }}</textarea>
            <p class="ob-field__help">{{ __('openbook.admin.settings.instance_rules_help') }}</p>
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

        <button type="submit" class="ob-btn ob-btn--primary" style="margin-top:1.25rem">{{ __('openbook.admin.settings.save') }}</button>
    </form>
@endsection
