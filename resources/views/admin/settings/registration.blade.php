        <div class="ob-field" style="margin-top:1rem">
            <label style="display:flex;align-items:center;gap:0.5rem;font-weight:500">
                <input type="hidden" name="registration_open" value="0">
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

        <div class="ob-field" style="margin-top:1rem">
            <label for="privacy_policy">{{ __('openbook.admin.settings.privacy_policy') }}</label>
            <textarea id="privacy_policy" name="privacy_policy" rows="8" maxlength="20000">{{ old('privacy_policy', $privacyPolicy) }}</textarea>
            <p class="ob-field__help">{{ __('openbook.admin.settings.privacy_policy_help') }}</p>
        </div>
