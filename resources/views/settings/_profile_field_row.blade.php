<div class="ob-settings-link-row" data-profile-field>
    <input type="hidden" name="links[{{ $index }}][kind]" value="{{ $kind }}">
    <input type="text" name="links[{{ $index }}][label]" maxlength="{{ \App\Domain\Profiles\ProfileFields::MAX_LABEL_LENGTH }}"
        aria-label="{{ __('openbook.settings.profile_field_label') }}"
        placeholder="{{ __('openbook.settings.'.($kind === 'link' ? 'link_label_placeholder' : 'information_label_placeholder')) }}"
        value="{{ $field['label'] }}" data-profile-field-label>
    <input type="{{ $kind === 'link' ? 'url' : 'text' }}" name="links[{{ $index }}][value]"
        maxlength="{{ $kind === 'link' ? \App\Domain\Profiles\ProfileFields::MAX_URL_LENGTH : \App\Domain\Profiles\ProfileFields::MAX_VALUE_LENGTH }}"
        aria-label="{{ __('openbook.settings.'.($kind === 'link' ? 'profile_link_value' : 'profile_information_value')) }}"
        placeholder="{{ $kind === 'link' ? 'https://...' : __('openbook.settings.information_value_placeholder') }}"
        value="{{ $field['value'] }}" data-profile-field-value>
    <button type="button" class="ob-btn ob-btn--ghost ob-btn--small" data-remove-profile-field
        aria-label="{{ __('openbook.settings.remove_profile_field') }}">{{ __('openbook.settings.remove_profile_field') }}</button>
</div>
