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

    @include('posts._feed', ['posts' => $posts ?? null, 'emptyMessage' => __('openbook.hashtags.empty')])
@endsection
