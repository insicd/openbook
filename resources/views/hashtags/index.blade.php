@extends('layouts.app')

@section('title', __('openbook.hashtags.index_title').' - '.config('app.name'))

@section('content')
    <div class="ob-card">
        <h1>{{ __('openbook.hashtags.index_title') }}</h1>
        <p class="ob-field__help">{{ __('openbook.hashtags.index_subtitle', ['days' => $trendingDays]) }}</p>
        <p class="ob-field__help">{{ __('openbook.hashtags.ticker_note') }}</p>
    </div>

    <div class="ob-card">
        @if ($hashtags->isEmpty())
            <div class="ob-empty-state">
                <p>{{ __('openbook.sidebar.no_popular_hashtags') }}</p>
            </div>
        @else
            <ul class="ob-hashtag-list ob-hashtag-list--full ob-hashtag-list--tickers @auth ob-hashtag-list--with-follow @endauth">
                @foreach ($hashtags as $hashtag)
                    <li>
                        <div class="ob-hashtag-list__topic">
                            <a href="{{ route('hashtags.show', $hashtag->name) }}">#{{ $hashtag->name }}</a>
                            <span class="ob-field__help">{{ trans_choice('openbook.hashtags.contents', (int) $hashtag->usage_count, ['count' => \App\Support\CompactNumber::format((int) $hashtag->usage_count)]) }}</span>
                        </div>
                        <div class="ob-hashtag-list__ticker">
                            @if (($tagTrends[$hashtag->id] ?? []) !== [])
                                @include('hashtags._sparkline', ['points' => $tagTrends[$hashtag->id], 'tagId' => $hashtag->id])
                            @endif
                        </div>
                        @auth
                        <div class="ob-hashtag-list__actions">
                            @include('hashtags._follow-button', [
                                'hashtagName' => $hashtag->name,
                                'isFollowing' => in_array($hashtag->id, $followedHashtagIds ?? [], true),
                            ])
                        </div>
                        @endauth
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection
