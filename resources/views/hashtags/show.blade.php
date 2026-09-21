@extends('layouts.app')

@section('title', '#'.$tagName.' - '.config('app.name'))

@section('content')
    <div class="ob-card ob-hashtag-header">
        <h1>#{{ $tagName }}</h1>
        @include('hashtags._follow-button', [
            'hashtagName' => $tagName,
            'isFollowing' => $isFollowing ?? false,
        ])
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
