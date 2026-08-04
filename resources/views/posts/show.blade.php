@extends('layouts.app')

@php
    $displayName = $post->actor->displayName();
@endphp

@section('title', __('openbook.posts.page_title', ['name' => $displayName]).' - '.config('app.name'))

@section('content')
    @include('posts._card', ['post' => $post, 'linkToPost' => false])

    <div id="commenta">
        @auth
            @include('composer.form', [
                'mode' => 'comment',
                'formId' => null,
                'bodyId' => 'comment-body',
                'prefix' => 'comment',
                'action' => route('comments.store', $post),
                'showLabel' => true,
                'bodyLabel' => __('openbook.comments.new_label'),
                'rows' => 3,
            ])
        @else
            <div class="ob-card">
                <p><a href="{{ route('login') }}">{{ __('openbook.comments.login_to_comment') }}</a></p>
            </div>
        @endauth
    </div>

    <div class="ob-card" id="commenti">
        <h2>{{ __('openbook.comments.title', ['count' => $post->comments_count]) }}</h2>

        @forelse ($commentTree as $node)
            @include('comments._comment', ['node' => $node, 'post' => $post])
        @empty
            <div class="ob-empty-state">
                <p>{{ __('openbook.comments.empty') }}</p>
            </div>
        @endforelse
    </div>
@endsection
