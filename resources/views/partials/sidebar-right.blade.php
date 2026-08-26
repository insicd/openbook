<div class="ob-card ob-side-widget">
    <h2 class="ob-side-widget__title">{{ __('openbook.sidebar.instance_title') }}</h2>
    <p class="ob-side-widget__instance">
        <strong>{{ config('app.name') }}</strong>
        <span class="ob-post__handle">{{ config('openbook.domain') }}</span>
    </p>
</div>

<div class="ob-card ob-side-widget">
    <h2 class="ob-side-widget__title">{{ __('openbook.sidebar.trending_title') }}</h2>

    @if (($popularHashtags ?? collect())->isNotEmpty())
        <ul class="ob-hashtag-list">
            @foreach ($popularHashtags as $hashtag)
                <li>
                    <a href="{{ route('hashtags.show', $hashtag->name) }}">#{{ $hashtag->name }}</a>
                    <span class="ob-field__help">{{ trans_choice('openbook.sidebar.hashtag_uses', (int) $hashtag->usage_count, ['count' => \App\Support\CompactNumber::format((int) $hashtag->usage_count)]) }}</span>
                </li>
            @endforeach
        </ul>
        @if ($popularHashtagsHasMore ?? false)
            <a href="{{ route('hashtags.index') }}" class="ob-side-widget__more">{{ __('openbook.sidebar.trending_more') }}</a>
        @endif
    @else
        <p class="ob-field__help">{{ __('openbook.sidebar.no_popular_hashtags') }}</p>
    @endif
</div>

@if (($suggestedActors ?? collect())->isNotEmpty())
    <div class="ob-card ob-side-widget">
        <h2 class="ob-side-widget__title">{{ __('openbook.sidebar.people_to_follow') }}</h2>
        @foreach ($suggestedActors as $actor)
            @include('world._suggestion', ['rowActor' => $actor])
        @endforeach
    </div>
@endif
