<div class="ob-card ob-side-widget">
    <h2 class="ob-side-widget__title">{{ __('openbook.sidebar.instance_title') }}</h2>
    <p class="ob-side-widget__instance">
        <strong>{{ config('app.name') }}</strong>
        <span class="ob-post__handle">{{ config('openbook.domain') }}</span>
    </p>
</div>

<div
    class="ob-card ob-side-widget"
    data-trending-widget
    data-url="{{ route('hashtags.sidebar') }}"
    data-loading-label="{{ __('openbook.sidebar.trending_loading') }}"
    data-empty-label="{{ __('openbook.sidebar.no_popular_hashtags') }}"
    data-error-label="{{ __('openbook.sidebar.trending_error') }}"
    data-retry-label="{{ __('openbook.sidebar.trending_retry') }}"
    data-more-label="{{ __('openbook.sidebar.trending_more') }}"
    data-more-url="{{ route('hashtags.index') }}"
>
    @php
        $trendingDays = max(1, (int) config('openbook.hashtags.trending_days', 7));
    @endphp
    <h2 class="ob-side-widget__title">{{ __('openbook.sidebar.trending_title', ['days' => $trendingDays.'d']) }}</h2>

    <div data-trending-content aria-live="polite"></div>
    <noscript><a href="{{ route('hashtags.index') }}">{{ __('openbook.nav.trending') }}</a></noscript>
</div>

@if (($suggestedActors ?? collect())->isNotEmpty())
    <div class="ob-card ob-side-widget">
        <h2 class="ob-side-widget__title">{{ __('openbook.sidebar.people_to_follow') }}</h2>
        @foreach ($suggestedActors as $actor)
            @include('world._suggestion', ['rowActor' => $actor])
        @endforeach
    </div>
@endif
