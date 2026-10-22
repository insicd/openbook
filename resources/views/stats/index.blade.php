@extends('layouts.app')

@section('title', __('stats.title').' · '.config('app.name'))

@push('head')
    <link rel="stylesheet" href="{{ \App\Support\Assets::url('assets/css/stats.css') }}">
    <script src="{{ \App\Support\Assets::url('assets/vendor/chartjs/chart.umd.min.js') }}" defer></script>
    <script src="{{ \App\Support\Assets::url('assets/js/stats.js') }}" defer></script>
@endpush

@section('content')
    <div class="ob-stats" data-stats data-timezone="{{ app(\App\Support\UserDate::class)->timezone() }}" data-locale="{{ str_replace('_', '-', app()->getLocale()) }}"
         data-labels="{{ json_encode(__('stats.chart'), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) }}">
        <header class="ob-stats__intro">
            <span class="ob-stats__eyebrow">{{ __('stats.title') }}</span>
            <h1>{{ __('stats.heading') }}</h1>
            <p>{{ __('stats.intro') }}</p>
        </header>

        @foreach (['rhythm', 'languages', 'domains', 'volume', 'authors', 'hashtags', 'geography', 'eventCalendar', 'communityActivity', 'attachments', 'mediaTrend', 'polls', 'acquisition'] as $metric)
            @php($dataset = in_array($metric, ['volume', 'authors']) ? 'weekly' : (in_array($metric, ['attachments', 'mediaTrend']) ? 'media' : $metric))
            <section class="ob-card ob-stats-card" data-stats-card="{{ $metric }}"
                     data-metric="{{ $dataset }}"
                     data-url="{{ route('stats.'.$dataset) }}" aria-labelledby="stats-{{ $metric }}-title">
                <header class="ob-stats-card__header">
                    <span class="ob-stats__eyebrow">{{ __('stats.'.$metric.'.eyebrow') }}</span>
                    <h2 id="stats-{{ $metric }}-title">{{ __('stats.'.$metric.'.title') }}</h2>
                    <p id="stats-{{ $metric }}-intro">{{ __('stats.'.$metric.'.description') }}</p>
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
                    @if ($metric === 'hashtags')
                        <p class="ob-stats-card__period">{{ __('stats.chart.legend_top') }}</p>
                        <div class="ob-stats-legend" data-stats-legend role="group" aria-label="{{ __('stats.chart.legend_top') }}"></div>
                    @endif
                    <p class="ob-stats-card__period" data-stats-period></p>
                    <div class="ob-stats-info" data-stats-info>
                        <button type="button" class="ob-stats-info__button" aria-label="{{ __('stats.chart.cache_info') }}"
                                aria-describedby="stats-{{ $metric }}-cache-info" aria-expanded="false" data-stats-info-button>i</button>
                        <span class="ob-stats-info__tooltip" id="stats-{{ $metric }}-cache-info" role="tooltip" data-stats-updated></span>
                    </div>
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
