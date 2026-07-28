@extends('layouts.app')

@section('title', __('openbook.settings.title').' - '.config('app.name'))

@section('content')
    <h1>{{ __('openbook.settings.title') }}</h1>

    <div class="ob-card">
        <h2>{{ __('openbook.settings.profile_section_title') }}</h2>

        <form method="POST" action="{{ route('settings.profile.update') }}" enctype="multipart/form-data">
            @csrf
            @method('PUT')

            <div class="ob-field">
                <label>{{ __('openbook.settings.avatar_label') }}</label>
                <div class="ob-settings-avatar-picker">
                    <x-avatar id="settings-avatar-preview" :user="$viewer" style="width:64px;height:64px;font-size:1.5rem" />
                    <input type="file" name="avatar" id="settings-avatar-input" accept="image/jpeg,image/png,image/webp,image/gif">
                </div>
                <p class="ob-field__help">{{ __('openbook.settings.image_preview_help') }}</p>
                @error('avatar')
                    <p class="ob-field__error">{{ $message }}</p>
                @enderror
            </div>

            <div class="ob-field">
                <label>{{ __('openbook.settings.cover_label') }}</label>
                <div id="settings-cover-preview">
                    @if ($viewer->profile?->coverUrl())
                        <img src="{{ $viewer->profile->coverUrl() }}" alt="" class="ob-settings-cover-preview">
                    @endif
                </div>
                <input type="file" name="cover" id="settings-cover-input" accept="image/jpeg,image/png,image/webp,image/gif">
                @error('cover')
                    <p class="ob-field__error">{{ $message }}</p>
                @enderror
            </div>

            <div class="ob-field">
                <label for="settings-display-name">{{ __('openbook.settings.display_name_label') }}</label>
                <input type="text" id="settings-display-name" name="display_name" maxlength="100" required
                    value="{{ old('display_name', $viewer->profile?->display_name) }}">
                @error('display_name')
                    <p class="ob-field__error">{{ $message }}</p>
                @enderror
            </div>

            <div class="ob-field">
                <label for="settings-bio">{{ __('openbook.settings.bio_label') }}</label>
                <textarea id="settings-bio" name="bio" maxlength="500" rows="3">{{ old('bio', $viewer->profile?->bio) }}</textarea>
                @error('bio')
                    <p class="ob-field__error">{{ $message }}</p>
                @enderror
            </div>

            <div class="ob-field">
                <label>{{ __('openbook.settings.links_label') }}</label>
                @php $existingLinks = old('links', $viewer->profile?->links ?: []); @endphp
                @for ($i = 0; $i < 4; $i++)
                    <div class="ob-settings-link-row">
                        <input type="text" name="links[{{ $i }}][label]" maxlength="50"
                            placeholder="{{ __('openbook.settings.link_label_placeholder') }}"
                            value="{{ $existingLinks[$i]['label'] ?? '' }}">
                        <input type="url" name="links[{{ $i }}][url]" maxlength="255"
                            placeholder="https://..."
                            value="{{ $existingLinks[$i]['url'] ?? '' }}">
                    </div>
                @endfor
                <p class="ob-field__help">{{ __('openbook.settings.links_help') }}</p>
                @error('links.*.url')
                    <p class="ob-field__error">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="ob-btn ob-btn--primary">{{ __('openbook.settings.save') }}</button>
        </form>
    </div>

    <div class="ob-card">
        <h2>{{ __('openbook.settings.account_section_title') }}</h2>

        <form method="POST" action="{{ route('settings.account.update') }}">
            @csrf
            @method('PUT')

            <div class="ob-field">
                <label for="settings-locale">{{ __('openbook.settings.locale_label') }}</label>
                <select id="settings-locale" name="locale">
                    @foreach (config('openbook.locales') as $code => $label)
                        <option value="{{ $code }}" @selected(old('locale', $viewer->settings?->locale) === $code)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('locale')
                    <p class="ob-field__error">{{ $message }}</p>
                @enderror
            </div>

            <div class="ob-field">
                <label for="settings-visibility">{{ __('openbook.settings.default_visibility_label') }}</label>
                @php $defaultVisibility = old('default_post_visibility', $viewer->settings?->default_post_visibility ?: 'public'); @endphp
                <select id="settings-visibility" name="default_post_visibility">
                    <option value="public" @selected($defaultVisibility === 'public')>{{ __('openbook.visibility.public') }}</option>
                    <option value="unlisted" @selected($defaultVisibility === 'unlisted')>{{ __('openbook.visibility.unlisted') }}</option>
                    <option value="followers" @selected($defaultVisibility === 'followers')>{{ __('openbook.visibility.followers') }}</option>
                    <option value="direct" @selected($defaultVisibility === 'direct')>{{ __('openbook.visibility.direct') }}</option>
                </select>
                @error('default_post_visibility')
                    <p class="ob-field__error">{{ $message }}</p>
                @enderror
            </div>

            <div class="ob-field">
                <label class="ob-checkbox">
                    <input type="checkbox" name="manually_approves_followers" value="1"
                        @checked(old('manually_approves_followers', $viewer->actor?->manually_approves_followers))>
                    {{ __('openbook.settings.protected_account_label') }}
                </label>
                <p class="ob-field__help">{{ __('openbook.settings.protected_account_help') }}</p>
            </div>

            <div class="ob-field">
                <label class="ob-checkbox">
                    <input type="checkbox" name="discoverable" value="1"
                        @checked(old('discoverable', $viewer->settings?->discoverable))>
                    {{ __('openbook.settings.discoverable_label') }}
                </label>
                <p class="ob-field__help">{{ __('openbook.settings.discoverable_help') }}</p>
            </div>

            <button type="submit" class="ob-btn ob-btn--primary">{{ __('openbook.settings.save') }}</button>
        </form>
    </div>

    <script>
        (function () {
            function previewImage(inputId, containerId, imgClass) {
                var input = document.getElementById(inputId);
                var container = document.getElementById(containerId);

                if (!input || !container || !window.FileReader) {
                    return;
                }

                input.addEventListener('change', function () {
                    var file = input.files && input.files[0];

                    if (!file || file.type.indexOf('image/') !== 0) {
                        return;
                    }

                    var reader = new FileReader();
                    reader.onload = function (event) {
                        var img = document.createElement('img');
                        img.src = event.target.result;
                        img.alt = '';
                        if (imgClass) {
                            img.className = imgClass;
                        }
                        container.innerHTML = '';
                        container.appendChild(img);
                    };
                    reader.readAsDataURL(file);
                });
            }

            previewImage('settings-avatar-input', 'settings-avatar-preview', null);
            previewImage('settings-cover-input', 'settings-cover-preview', 'ob-settings-cover-preview');
        })();
    </script>
@endsection
