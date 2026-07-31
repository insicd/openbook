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
                    <span class="ob-field__help">{{ trans_choice('openbook.sidebar.hashtag_uses', $hashtag->usage_count, ['count' => $hashtag->usage_count]) }}</span>
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
            @php
                $suggestedUser = $actor->user;
                $suggestedName = $suggestedUser?->profile?->display_name ?: $suggestedUser?->username;
                $suggestedHandle = $suggestedUser ? '@'.$suggestedUser->username : '';
            @endphp
            @if ($suggestedUser)
                <div class="ob-suggestion">
                    <a href="{{ route('profile.show', $suggestedUser->username) }}" class="ob-mini-profile__link">
                        <x-avatar :user="$suggestedUser" style="width:40px;height:40px" />
                        <div>
                            <div class="ob-post__author">{{ $suggestedName }}</div>
                            <div class="ob-post__handle">{{ $suggestedHandle }}</div>
                        </div>
                    </a>
                    <form method="POST" action="{{ route('follow.store', $suggestedUser) }}">
                        @csrf
                        <button type="submit" class="ob-btn ob-btn--ghost ob-btn--small">{{ __('openbook.follow.follow') }}</button>
                    </form>
                </div>
            @endif
        @endforeach
    </div>
@endif
