@extends('layouts.app')

@section('title', '#'.$tagName.' - '.config('app.name'))

@if (($tagTrend ?? []) !== [])
    @push('head')
        <script src="{{ \App\Support\Assets::url('assets/vendor/chartjs/chart.umd.min.js') }}" defer></script>
        <script src="{{ \App\Support\Assets::url('assets/js/hashtag-trend.js') }}" defer></script>
    @endpush
@endif

@section('content')
    <div class="ob-card ob-hashtag-header">
        <h1>#{{ $tagName }}</h1>
        @include('hashtags._follow-button', [
            'hashtagName' => $tagName,
            'isFollowing' => $isFollowing ?? false,
        ])
        @if (($tagTrend ?? []) !== [])
            <div class="ob-hashtag-trend" data-hashtag-trend hidden
                data-rows="{{ json_encode($tagTrend, JSON_THROW_ON_ERROR) }}">
                <p class="ob-hashtag-trend__title">
                    <span>{{ __('openbook.hashtags.trend_heading') }}</span>
                    <small>{{ __('openbook.hashtags.trend_period') }}</small>
                </p>
                <div class="ob-hashtag-trend__canvas" data-hashtag-trend-canvas>
                    <canvas role="img" aria-label="{{ __('openbook.hashtags.trend_title') }}"></canvas>
                </div>
            </div>
        @endif
    </div>

    @if (($events ?? collect())->isNotEmpty())
        <section class="ob-card">
            <h2>{{ __('openbook.search.events') }}</h2>
            <div class="ob-event-grid">
                @foreach ($events as $event)
                    @include('events._card', ['event' => $event])
                @endforeach
            </div>
        </section>
    @endif

    @include('posts._feed', ['posts' => $posts ?? null, 'emptyMessage' => __('openbook.hashtags.empty')])
@endsection
