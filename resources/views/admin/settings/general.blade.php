        <div class="ob-field">
            <label for="site_name">{{ __('openbook.admin.settings.site_name') }}</label>
            <input type="text" id="site_name" name="site_name" value="{{ old('site_name', $siteName) }}" required maxlength="100">
        </div>

        <div class="ob-field" style="margin-top:1rem">
            <label for="site_description">{{ __('openbook.admin.settings.site_description') }}</label>
            <textarea id="site_description" name="site_description" rows="3" maxlength="500">{{ old('site_description', $siteDescription) }}</textarea>
            <p class="ob-field__help">{{ __('openbook.admin.settings.site_description_help') }}</p>
        </div>

        <div class="ob-field" style="margin-top:1rem">
            <label for="contact_email">{{ __('openbook.admin.settings.contact_email') }}</label>
            <input type="email" id="contact_email" name="contact_email" value="{{ old('contact_email', $contactEmail) }}" maxlength="254" autocomplete="email">
            <p class="ob-field__help">{{ __('openbook.admin.settings.contact_email_help') }}</p>
            @error('contact_email')
                <p class="ob-field__error">{{ $message }}</p>
            @enderror
        </div>

        <div class="ob-field" style="margin-top:1rem">
            <label for="contact_account_id">{{ __('openbook.admin.settings.contact_account') }}</label>
            <select id="contact_account_id" name="contact_account_id">
                <option value="">{{ __('openbook.admin.settings.contact_account_none') }}</option>
                @foreach ($contactAccounts as $contactAccount)
                    <option value="{{ $contactAccount->id }}" @selected(old('contact_account_id', $contactAccountId) === $contactAccount->id)>{{ '@'.$contactAccount->username }}</option>
                @endforeach
            </select>
            <p class="ob-field__help">{{ __('openbook.admin.settings.contact_account_help') }}</p>
            @error('contact_account_id')
                <p class="ob-field__error">{{ $message }}</p>
            @enderror
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
            <label for="home_background">{{ __('openbook.admin.settings.home_background') }}</label>
            @if ($homeBackgroundUrl)
                <img
                    class="ob-admin-home-background-preview"
                    src="{{ $homeBackgroundUrl }}"
                    alt=""
                    width="240"
                    height="120"
                >
            @endif
            <input type="file" name="home_background" id="home_background" accept="image/jpeg,image/png,image/webp,image/gif">
            <p class="ob-field__help">{{ __('openbook.admin.settings.home_background_help') }}</p>
            @if ($homeBackgroundUrl)
                <label class="ob-checkbox" style="margin-top:0.5rem">
                    <input type="checkbox" name="remove_home_background" value="1">
                    {{ __('openbook.admin.settings.home_background_remove') }}
                </label>
            @endif
            @error('home_background')
                <p class="ob-field__error">{{ $message }}</p>
            @enderror
        </div>

        <div class="ob-field" style="margin-top:1rem">
            <label style="display:flex;align-items:center;gap:0.5rem;font-weight:500">
                <input type="hidden" name="show_home_staff" value="0">
                <input type="checkbox" name="show_home_staff" value="1" @checked(old('show_home_staff', $showHomeStaff))>
                {{ __('openbook.admin.settings.show_home_staff') }}
            </label>
            <p class="ob-field__help">{{ __('openbook.admin.settings.show_home_staff_help') }}</p>
        </div>
