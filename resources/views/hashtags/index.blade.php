@extends('layouts.app')

@section('title', __('openbook.hashtags.index_title').' - '.config('app.name'))

@section('content')
    <div class="ob-card">
        <h1>{{ __('openbook.hashtags.index_title') }}</h1>
        <p class="ob-field__help">{{ __('openbook.hashtags.index_subtitle') }}</p>
    </div>

    <div class="ob-card">
        @if ($hashtags->isEmpty())
            <div class="ob-empty-state">
                <p>{{ __('openbook.sidebar.no_popular_hashtags') }}</p>
            </div>
        @else
            <ul class="ob-hashtag-list ob-hashtag-list--full">
                @foreach ($hashtags as $hashtag)
                    <li>
                        <a href="{{ route('hashtags.show', $hashtag->name) }}">#{{ $hashtag->name }}</a>
                        <span class="ob-field__help">{{ trans_choice('openbook.sidebar.hashtag_uses', $hashtag->usage_count, ['count' => $hashtag->usage_count]) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection
