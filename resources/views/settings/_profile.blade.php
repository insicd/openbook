<section class="ob-settings-section" aria-labelledby="settings-profile-title">
    <div class="ob-card">
        <h2 id="settings-profile-title">{{ __('openbook.settings.profile_section_title') }}</h2>

        <form id="settings-profile-form" method="POST" action="{{ route('settings.profile.update') }}" enctype="multipart/form-data">
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

            @include('settings._profile_fields')
        </form>
    </div>
    <div class="ob-settings-actions">
        <button type="submit" form="settings-profile-form" class="ob-btn ob-btn--primary">{{ __('openbook.settings.save') }}</button>
    </div>
</section>
