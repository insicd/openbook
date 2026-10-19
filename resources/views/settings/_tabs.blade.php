<nav class="ob-settings-tabs" aria-label="{{ __('openbook.settings.tabs_aria') }}">
    @foreach (['profile' => 'tab_profile', 'account' => 'tab_account', 'notifications' => 'tab_notifications', 'migration' => 'tab_migration', 'import_export' => 'tab_import_export'] as $tab => $label)
        <a href="{{ route('settings.edit', ['tab' => $tab]) }}"
           class="ob-profile-tabs__tab {{ $activeTab === $tab ? 'is-active' : '' }}"
           @if ($activeTab === $tab) aria-current="page" @endif>{{ __('openbook.settings.'.$label) }}</a>
    @endforeach
</nav>
