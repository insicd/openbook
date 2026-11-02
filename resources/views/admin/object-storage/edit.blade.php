@extends('layouts.admin')

@section('title', __('object_storage.title').' - '.config('app.name'))

@section('content')
    <h1>{{ __('object_storage.title') }}</h1>
    <p class="ob-field__help">{{ __('object_storage.intro') }}</p>

    <form method="POST" action="{{ route('admin.object_storage.update') }}" class="ob-card" style="margin-top:1rem" id="object-storage-settings">
        @csrf
        @method('PUT')
        <section class="ob-storage-status" aria-label="{{ __('object_storage.status_label') }}">
            <div class="ob-storage-status__row">
                <span class="ob-storage-status__badge {{ $settings['enabled'] ? 'is-active' : '' }}"><span aria-hidden="true"></span>{{ __('object_storage.'.($settings['enabled'] ? 'status_active' : 'status_inactive')) }}</span>
                <label class="ob-checkbox">
                    <input type="hidden" name="enabled" value="0">
                    <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $settings['enabled'])) aria-describedby="storage-status-help">
                    {{ __('object_storage.enabled') }}
                </label>
            </div>
            <p class="ob-field__help" id="storage-status-help">{{ __('object_storage.upload_notice') }}</p>
        </section>
        <div class="ob-field" style="margin-top:1rem">
            <label for="provider">{{ __('object_storage.provider') }}</label>
            <select name="provider" id="provider">
                @foreach (\App\Application\Services\ObjectStorageSettings::PROVIDERS as $provider)
                    <option value="{{ $provider }}" @selected(old('provider', $settings['provider']) === $provider)>{{ __('object_storage.providers.'.$provider) }}</option>
                @endforeach
            </select>
        </div>
        @foreach (['account_id' => 32, 'region' => 64, 'endpoint' => 2048, 'bucket' => 63, 'access_key' => 256] as $field => $maxLength)
            <div class="ob-field" style="margin-top:1rem" @if(in_array($field, ['account_id', 'region', 'endpoint'])) data-provider-field="{{ $field }}" @endif>
                <label for="{{ $field }}">{{ __('object_storage.'.$field) }}</label>
                <input type="{{ $field === 'endpoint' ? 'url' : 'text' }}" name="{{ $field }}" id="{{ $field }}" value="{{ old($field, $settings[$field]) }}" maxlength="{{ $maxLength }}" autocomplete="off">
                @if (in_array($field, ['account_id', 'region', 'endpoint']))
                    <p class="ob-field__help">{{ __('object_storage.'.$field.'_help') }}</p>
                @endif
            </div>
        @endforeach
        <div class="ob-field" style="margin-top:1rem">
            <label for="password">{{ __('object_storage.password') }}</label>
            <input type="password" name="password" id="password" autocomplete="new-password" maxlength="2048">
            <p class="ob-field__help">{{ __('object_storage.'.($hasSecret ? 'password_saved' : 'password_help')) }}</p>
        </div>
        <div data-provider-field="path_style" style="margin-top:1rem">
            <label class="ob-checkbox">
                <input type="hidden" name="path_style" value="0">
                <input type="checkbox" name="path_style" value="1" @checked(old('path_style', $settings['path_style']))>
                {{ __('object_storage.path_style') }}
            </label>
            <p class="ob-field__help">{{ __('object_storage.path_style_help') }}</p>
        </div>
        <label class="ob-checkbox" style="margin-top:1rem">
            <input type="hidden" name="direct" value="0">
            <input type="checkbox" name="direct" id="direct" value="1" @checked(old('direct', $settings['direct']))>
            {{ __('object_storage.direct') }}
        </label>
        <div class="ob-field" style="margin-top:1rem" id="public-url-field">
            <label for="public_url">{{ __('object_storage.public_url') }}</label>
            <input type="url" name="public_url" id="public_url" value="{{ old('public_url', $settings['public_url']) }}" maxlength="2048">
            <p class="ob-field__help">{{ __('object_storage.public_url_help') }}</p>
        </div>
        <p class="ob-field__help">{{ __('object_storage.bucket_change_help') }}</p>
        <div style="display:flex;flex-wrap:wrap;gap:0.75rem;margin-top:1rem">
            <button type="submit" class="ob-btn ob-btn--primary">{{ __('object_storage.save') }}</button>
            <button type="submit" form="object-storage-verify" class="ob-btn ob-btn--ghost">{{ __('object_storage.verify') }}</button>
        </div>
        <p class="ob-field__help">{{ __('object_storage.verify_help') }}</p>
    </form>
    <form method="POST" action="{{ route('admin.object_storage.verify') }}" id="object-storage-verify">
        @csrf
    </form>
    <script src="{{ \App\Support\Assets::url('assets/js/object-storage-settings.js') }}" defer></script>
@endsection
