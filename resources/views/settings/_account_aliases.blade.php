<div class="ob-card" id="account-aliases">
    <h2>{{ __('openbook.settings.alias_section_title') }}</h2>
    <p>{{ __('openbook.settings.alias_help') }}</p>
    <p class="ob-field__help">{{ __('openbook.settings.alias_public_help') }}</p>

    @if ($viewer->actor?->also_known_as)
        <ul class="ob-settings-aliases">
            @foreach ($viewer->actor->also_known_as as $alias)
                <li>
                    <a href="{{ $alias }}" target="_blank" rel="nofollow noopener noreferrer">{{ $alias }}</a>
                    <form method="POST" action="{{ route('settings.aliases.destroy') }}">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="alias" value="{{ $alias }}">
                        <button type="submit" class="ob-btn ob-btn--secondary">{{ __('openbook.settings.alias_remove') }}</button>
                    </form>
                </li>
            @endforeach
        </ul>
        <p class="ob-field__help">{{ __('openbook.settings.alias_remove_help') }}</p>
    @endif

    <form method="POST" action="{{ route('settings.aliases.store') }}">
        @csrf
        <div class="ob-field">
            <label for="settings-alias">{{ __('openbook.settings.alias_label') }}</label>
            <input type="text" id="settings-alias" name="alias" value="{{ old('alias') }}" maxlength="2048" required
                placeholder="name@example.org" aria-describedby="settings-alias-help">
            <p id="settings-alias-help" class="ob-field__help">{{ __('openbook.settings.alias_input_help', ['max' => \App\Application\Services\AccountAliasManager::MAX_ALIASES]) }}</p>
            @error('alias')
                <p class="ob-field__error">{{ $message }}</p>
            @enderror
        </div>
        <button type="submit" class="ob-btn ob-btn--primary">{{ __('openbook.settings.alias_add') }}</button>
    </form>
</div>
