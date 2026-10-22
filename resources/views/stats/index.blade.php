@extends('layouts.app')

@section('title', __('stats.title').' · '.config('app.name'))

@push('head')
    <link rel="stylesheet" href="{{ \App\Support\Assets::url('assets/css/stats.css') }}">
    <script src="{{ \App\Support\Assets::url('assets/vendor/chartjs/chart.umd.min.js') }}" defer></script>
    <script src="{{ \App\Support\Assets::url('assets/js/stats.js') }}" defer></script>
@endpush

@section('content')
    <div class="ob-stats" data-stats data-locale="{{ str_replace('_', '-', app()->getLocale()) }}"
         data-labels="{{ json_encode(__('stats.chart'), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) }}">
        <header class="ob-stats__intro">
            <span class="ob-stats__eyebrow">{{ __('stats.title') }}</span>
            <h1>{{ __('stats.heading') }}</h1>
            <p>{{ __('stats.intro') }}</p>
        </header>

        @foreach (['rhythm', 'languages', 'domains', 'volume', 'authors'] as $metric)
            <section class="ob-card ob-stats-card" data-stats-card="{{ $metric }}"
                     data-metric="{{ in_array($metric, ['volume', 'authors']) ? 'weekly' : $metric }}"
                     data-url="{{ route('stats.'.(in_array($metric, ['volume', 'authors']) ? 'weekly' : $metric)) }}" aria-labelledby="stats-{{ $metric }}-title">
                <header class="ob-stats-card__header">
                    <span class="ob-stats__eyebrow">{{ __('stats.'.$metric.'.eyebrow') }}</span>
                    <h2 id="stats-{{ $metric }}-title">{{ __('stats.'.$metric.'.title') }}</h2>
                    <p>{{ __('stats.'.$metric.'.description') }}</p>
                </header>

                <div class="ob-stats-card__status" role="status" data-stats-status>{{ __('stats.waiting') }}</div>
                <div class="ob-stats-card__actions">
                    <button type="button" class="ob-btn ob-btn--secondary ob-btn--small" data-stats-retry hidden>{{ __('stats.retry') }}</button>
                    <a href="{{ route('login') }}" class="ob-btn ob-btn--secondary ob-btn--small" data-stats-login hidden>{{ __('stats.login') }}</a>
                </div>

                <div data-stats-results hidden>
                    <div class="ob-stats-summary" data-stats-summary></div>
                    <div class="ob-stats-chart">
                        <canvas data-stats-canvas role="img" aria-label="{{ __('stats.'.$metric.'.title') }}"
                                aria-describedby="stats-{{ $metric }}-description"></canvas>
                    </div>
                    <p class="ob-stats-card__period" data-stats-period></p>
                    <p class="ob-stats-card__updated" data-stats-updated></p>
                </div>
                <p class="ob-stats-card__note" id="stats-{{ $metric }}-description">{{ __('stats.'.$metric.'.note') }}</p>
            </section>
        @endforeach

        <aside class="ob-stats-method">
            <h2>{{ __('stats.method_title') }}</h2>
            <p>{{ __('stats.method') }}</p>
        </aside>
        <noscript><p class="ob-alert ob-alert--error">{{ __('stats.no_js') }}</p></noscript>
    </div>
@endsection
