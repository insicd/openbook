@extends('layouts.app')

@section('title', __('openbook.nav.world').' - '.config('app.name'))

@section('content')
    <div class="ob-card">
        <h1 style="margin-bottom:0.25rem">{{ __('openbook.world.title') }}</h1>
        <p class="ob-field__help">{{ __('openbook.world.subtitle') }}</p>
    </div>

    <div
        class="ob-card ob-side-widget"
        data-world-suggestions
        data-url="{{ route('world.suggestions') }}"
        data-error-label="{{ __('openbook.world.suggested_error') }}"
        data-retry-label="{{ __('openbook.world.suggested_retry') }}"
    >
        <h2 class="ob-side-widget__title">{{ __('openbook.world.suggested_title') }}</h2>
        <div data-world-suggestions-content aria-live="polite">
            <p class="ob-field__help">{{ __('openbook.world.suggested_loading') }}</p>
        </div>
        <noscript><a href="{{ route('world.discover') }}">{{ __('openbook.world.suggested_more') }}</a></noscript>
    </div>

    @include('posts._feed', [
        'initialLoading' => true,
        'initialUrl' => request()->fullUrl(),
        'emptyMessage' => __('openbook.world.empty'),
        'asyncFeed' => true,
    ])
    <noscript>
        <div class="ob-card ob-empty-state">
            <p>{{ __('openbook.world.requires_js') }}</p>
        </div>
    </noscript>

    <script src="{{ \App\Support\Assets::url('assets/js/world-suggestions.js') }}" defer></script>
@endsection
