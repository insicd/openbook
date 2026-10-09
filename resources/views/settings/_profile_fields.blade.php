@php
    $fieldGroups = ['link' => [], 'text' => []];
    $existingFields = old('links', $viewer->profile?->links ?: []);
    foreach (is_array($existingFields) ? $existingFields : [] as $field) {
        if (! is_array($field) || ! is_string($field['label'] ?? '') || ! is_string($field['value'] ?? $field['url'] ?? '')) {
            continue;
        }
        $value = \App\Domain\Profiles\ProfileFields::value($field);
        $kind = in_array($field['kind'] ?? null, ['link', 'text'], true)
            ? $field['kind']
            : (\App\Domain\Profiles\ProfileFields::isLink($value) ? 'link' : 'text');
        $fieldGroups[$kind][] = ['label' => $field['label'] ?? '', 'value' => $value];
    }
    $fieldIndex = 0;
@endphp
<div data-profile-fields data-max-fields="{{ \App\Domain\Profiles\ProfileFields::MAX_FIELDS }}">
    @foreach (['link', 'text'] as $kind)
        <section class="ob-field" aria-labelledby="profile-fields-{{ $kind }}">
            <h3 id="profile-fields-{{ $kind }}">{{ __('openbook.settings.'.($kind === 'link' ? 'links_label' : 'additional_fields_label')) }}</h3>
            <div data-profile-field-list="{{ $kind }}">
                @foreach ($fieldGroups[$kind] as $field)
                    @include('settings._profile_field_row', ['index' => $fieldIndex++, 'field' => $field, 'kind' => $kind])
                @endforeach
            </div>
            <button type="button" class="ob-btn ob-btn--secondary ob-btn--small" data-add-profile-field="{{ $kind }}">
                + {{ __('openbook.settings.'.($kind === 'link' ? 'add_link' : 'add_information')) }}
            </button>
            <template data-profile-field-template="{{ $kind }}">
                @include('settings._profile_field_row', ['index' => '__INDEX__', 'field' => ['label' => '', 'value' => ''], 'kind' => $kind])
            </template>
        </section>
    @endforeach
    <p class="ob-field__help" data-profile-field-count aria-live="polite"
        data-count-label="{{ __('openbook.settings.profile_fields_count', ['count' => ':count', 'max' => \App\Domain\Profiles\ProfileFields::MAX_FIELDS]) }}">
        {{ __('openbook.settings.profile_fields_help', ['max' => \App\Domain\Profiles\ProfileFields::MAX_FIELDS]) }}
    </p>
    @error('links')
        <p class="ob-field__error">{{ $message }}</p>
    @enderror
    @foreach ($errors->get('links.*') as $messages)
        @foreach ($messages as $message)
            <p class="ob-field__error">{{ $message }}</p>
        @endforeach
    @endforeach
</div>
