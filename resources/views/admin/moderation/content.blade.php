<form method="POST" action="{{ route('admin.moderation.update') }}" class="ob-card" style="margin-top:1rem">
    @csrf
    @method('PUT')
        <h2 style="margin-top:1.5rem;font-size:1.1rem">{{ __('openbook.admin.settings.world_moderation_title') }}</h2>

        <div class="ob-field" style="margin-top:1rem">
            <label style="display:flex;align-items:center;gap:0.5rem;font-weight:500">
                <input type="hidden" name="world_hide_content_warnings" value="0">
                <input type="checkbox" name="world_hide_content_warnings" value="1" @checked(old('world_hide_content_warnings', $worldHideContentWarnings))>
                {{ __('openbook.admin.settings.world_hide_content_warnings') }}
            </label>
            <p class="ob-field__help">{{ __('openbook.admin.settings.world_hide_content_warnings_help') }}</p>
        </div>

        <div class="ob-field" style="margin-top:1rem">
            <label for="forced_content_warning_hashtags">{{ __('openbook.admin.settings.forced_content_warning_hashtags') }}</label>
            <textarea id="forced_content_warning_hashtags" name="forced_content_warning_hashtags" rows="5" maxlength="4000" placeholder="nudes&#10;porno">{{ old('forced_content_warning_hashtags', $forcedContentWarningHashtags) }}</textarea>
            <p class="ob-field__help">{{ __('openbook.admin.settings.forced_content_warning_hashtags_help') }}</p>
        </div>

    <button type="submit" class="ob-btn ob-btn--primary" style="margin-top:1.25rem">{{ __('openbook.admin.settings.save') }}</button>
</form>
