<section class="ob-settings-section" aria-labelledby="settings-aliases-title">
    <div class="ob-card" id="account-aliases">
        <h2 id="settings-aliases-title">{{ __('openbook.settings.alias_section_title') }}</h2>
        <p>{{ __('openbook.settings.alias_help', ['app' => config('app.name')]) }}</p>
        <p class="ob-field__help">{{ __('openbook.settings.alias_public_help') }}</p>

        @if ($viewer->actor?->also_known_as)
            <ul class="ob-settings-aliases">
                @foreach ($viewer->actor->also_known_as as $alias)
                    <li>
                        <div class="ob-settings-alias-row">
                            <a href="{{ $alias }}" target="_blank" rel="nofollow noopener noreferrer">{{ $alias }}</a>
                            <form method="POST" action="{{ route('settings.aliases.destroy') }}">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="alias" value="{{ $alias }}">
                                <button type="submit" class="ob-btn ob-btn--secondary ob-btn--small">{{ __('openbook.settings.alias_remove') }}</button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>
            <p class="ob-field__help">{{ __('openbook.settings.alias_remove_help') }}</p>
        @endif

        <form id="settings-alias-form" method="POST" action="{{ route('settings.aliases.store') }}">
            @csrf
            <div class="ob-field">
                <label for="settings-alias">{{ __('openbook.settings.alias_label') }}</label>
                <input type="text" id="settings-alias" name="alias" value="{{ old('alias') }}" maxlength="2048" required
                    placeholder="{{ __('openbook.settings.account_address_example') }}" aria-describedby="settings-alias-help">
                <p id="settings-alias-help" class="ob-field__help">{{ __('openbook.settings.alias_input_help', ['max' => \App\Application\Services\AccountAliasManager::MAX_ALIASES, 'example' => __('openbook.settings.account_address_example')]) }}</p>
                @error('alias')
                    <p class="ob-field__error">{{ $message }}</p>
                @enderror
            </div>
        </form>
    </div>
    <div class="ob-settings-actions">
        <button type="submit" form="settings-alias-form" class="ob-btn ob-btn--primary">{{ __('openbook.settings.alias_add') }}</button>
    </div>
</section>
