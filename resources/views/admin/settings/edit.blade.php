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

        <button type="submit" class="ob-btn ob-btn--primary" style="margin-top:1.25rem">{{ __('openbook.admin.settings.save') }}</button>
    </form>
@endsection
