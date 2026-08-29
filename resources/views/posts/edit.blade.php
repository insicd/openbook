@extends('layouts.app')

@section('title', __('openbook.posts.edit_title').' - '.config('app.name'))

@section('content')
    <p class="ob-field__help" style="margin-bottom:0.5rem">
        <a href="{{ route('posts.show', $post) }}">&larr; {{ __('openbook.posts.back_to_post') }}</a>
    </p>
    <h1>{{ __('openbook.posts.edit_title') }}</h1>

    @include('posts._composer', [
        'action' => route('posts.update', $post),
        'method' => 'PUT',
        'editingPost' => $post,
        'quotedPost' => $post->quotedPost,
        'composerCommunities' => collect(),
    ])
@endsection
