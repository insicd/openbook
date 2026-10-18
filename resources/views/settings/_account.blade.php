<section class="ob-settings-section" aria-labelledby="settings-account-title">
    <div class="ob-card">
        <h2 id="settings-account-title">{{ __('openbook.settings.account_section_title') }}</h2>

        <form id="settings-account-form" method="POST" action="{{ route('settings.account.update') }}">
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
                <label for="settings-timezone">{{ __('openbook.settings.timezone_label') }}</label>
                @php $selectedTimezone = old('timezone', $viewer->settings?->timezone); @endphp
                <select id="settings-timezone" name="timezone" aria-describedby="settings-timezone-help">
                    <option value="" @selected($selectedTimezone === null || $selectedTimezone === '')>{{ __('openbook.settings.timezone_automatic') }}</option>
                    @foreach (\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC) as $timezone)
                        <option value="{{ $timezone }}" @selected($selectedTimezone === $timezone)>{{ str_replace('_', ' ', $timezone) }}</option>
                    @endforeach
                </select>
                <p id="settings-timezone-help" class="ob-field__help">{{ __('openbook.settings.timezone_help') }}</p>
                @error('timezone')
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

            <div class="ob-field">
                <label class="ob-checkbox">
                    <input type="checkbox" name="indexable" value="1"
                        @checked(old('indexable', $viewer->settings?->indexable))>
                    {{ __('openbook.settings.indexable_label') }}
                </label>
                <p class="ob-field__help">{{ __('openbook.settings.indexable_help') }}</p>
            </div>

            <div class="ob-field">
                <label for="settings-dm-policy">{{ __('openbook.settings.direct_message_policy_label') }}</label>
                @php $dmPolicy = old('direct_message_policy', $viewer->settings?->direct_message_policy ?: 'everyone'); @endphp
                <select id="settings-dm-policy" name="direct_message_policy">
                    <option value="everyone" @selected($dmPolicy === 'everyone')>{{ __('openbook.settings.direct_message_policy_everyone') }}</option>
                    <option value="followers" @selected($dmPolicy === 'followers')>{{ __('openbook.settings.direct_message_policy_followers') }}</option>
                    <option value="nobody" @selected($dmPolicy === 'nobody')>{{ __('openbook.settings.direct_message_policy_nobody') }}</option>
                </select>
                <p class="ob-field__help">{{ __('openbook.settings.direct_message_policy_help') }}</p>
                @error('direct_message_policy')
                    <p class="ob-field__error">{{ $message }}</p>
                @enderror
            </div>

        </form>
    </div>
    <div class="ob-settings-actions">
        <button type="submit" form="settings-account-form" class="ob-btn ob-btn--primary">{{ __('openbook.settings.save') }}</button>
    </div>
</section>
