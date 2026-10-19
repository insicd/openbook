<section class="ob-settings-section" aria-labelledby="settings-migration-title">
    <div class="ob-card" id="account-migration">
        <h2 id="settings-migration-title">{{ __('openbook.settings.migration_title') }}</h2>
        @if ($viewer->actor?->movedTo !== null)
            <p>{{ __('openbook.settings.migration_notice') }}</p>
            <p class="ob-migration-destination"><a href="{{ $viewer->actor->movedTo->uri }}" rel="nofollow noopener noreferrer">{{ $viewer->actor->movedTo->uri }}</a></p>
            <p>{{ __('openbook.settings.migration_remove_help') }}</p>
            <form id="settings-migration-remove-form" method="POST" action="{{ route('settings.migration.destroy') }}">
                @csrf
                @method('DELETE')
            </form>
        @else
            <p>{{ __('openbook.settings.migration_help', ['app' => config('app.name')]) }}</p>
            <form id="settings-migration-form" method="POST" action="{{ route('settings.migration.preview') }}">
                @csrf
                <div class="ob-field">
                    <label for="migration-destination">{{ __('openbook.settings.migration_destination') }}</label>
                    <input id="migration-destination" type="text" name="destination" value="{{ old('destination') }}" maxlength="2048" required
                        placeholder="{{ __('openbook.settings.account_address_example') }}" aria-describedby="migration-help">
                    <p id="migration-help" class="ob-field__help">{{ __('openbook.settings.migration_input_help', ['example' => __('openbook.settings.account_address_example')]) }}</p>
                    @error('destination')<p class="ob-field__error">{{ $message }}</p>@enderror
                    @error('password')<p class="ob-field__error">{{ $message }}</p>@enderror
                    @error('confirm_migration')<p class="ob-field__error">{{ $message }}</p>@enderror
                </div>
            </form>
        @endif
    </div>
    <div class="ob-settings-actions">
        @if ($viewer->actor?->movedTo !== null)
            <button type="submit" form="settings-migration-remove-form" class="ob-btn ob-btn--secondary">{{ __('openbook.settings.migration_remove') }}</button>
        @else
            <button type="submit" form="settings-migration-form" class="ob-btn ob-btn--primary">{{ __('openbook.settings.migration_verify') }}</button>
        @endif
    </div>
</section>
