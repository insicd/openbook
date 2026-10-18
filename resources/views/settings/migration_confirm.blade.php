@extends('layouts.app')

@section('title', __('openbook.settings.migration_title').' - '.config('app.name'))

@section('content')
    <h1>{{ __('openbook.settings.migration_title') }}</h1>
    @include('settings._tabs', ['activeTab' => 'migration'])

    <section class="ob-settings-section">
        <div class="ob-card">
            <h2 class="ob-migration-destination">{{ '@'.$destination->handle() }}</h2>
            <p class="ob-migration-destination"><a href="{{ $destination->uri }}" rel="nofollow noopener noreferrer">{{ $destination->uri }}</a></p>
            <p>{{ __('openbook.settings.migration_confirm_help', ['app' => config('app.name')]) }}</p>
            <form id="settings-migration-confirm-form" method="POST" action="{{ route('settings.migration.store') }}">
                @csrf
                <input type="hidden" name="destination" value="{{ $destination->uri }}">
                <div class="ob-field">
                    <label for="migration-password">{{ __('openbook.settings.migration_password', ['app' => config('app.name')]) }}</label>
                    <input id="migration-password" type="password" name="password" autocomplete="current-password" required>
                </div>
                <div class="ob-field">
                    <label class="ob-checkbox">
                        <input type="checkbox" name="confirm_migration" value="1" required>
                        {{ __('openbook.settings.migration_confirm_label') }}
                    </label>
                </div>
            </form>
        </div>
        <div class="ob-settings-actions">
            <button type="submit" form="settings-migration-confirm-form" class="ob-btn ob-btn--primary">{{ __('openbook.settings.migration_confirm') }}</button>
            <a href="{{ route('settings.edit', ['tab' => 'migration']) }}" class="ob-btn ob-btn--secondary">{{ __('openbook.settings.migration_back') }}</a>
        </div>
    </section>
@endsection
