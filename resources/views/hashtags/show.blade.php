@extends('layouts.app')

@section('title', '#'.$tagName.' - '.config('app.name'))

@section('content')
    <div class="ob-card">
        <h1>#{{ $tagName }}</h1>
    </div>

    @include('posts._feed', ['posts' => $posts ?? null, 'emptyMessage' => __('openbook.hashtags.empty')])
@endsection
