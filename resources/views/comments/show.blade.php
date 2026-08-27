@extends('layouts.app')

@php
    $displayName = $comment->actor->displayName();
@endphp

@section('title', __('openbook.comments.thread_title', ['name' => $displayName]).' - '.config('app.name'))

@section('content')
    <p class="ob-field__help" style="margin-bottom:0.75rem">
        <a href="{{ route('posts.show', $post) }}#commento-{{ $comment->id }}">&larr; {{ __('openbook.posts.back_to_post') }}</a>
    </p>

    @include('posts._card', ['post' => $post, 'linkToPost' => true])

    <div class="ob-card ob-thread" id="commenti">
        @if ($ancestors !== [])
            <div class="ob-thread__ancestors">
                @foreach ($ancestors as $ancestor)
                    @include('comments._comment', [
                        'node' => ['comment' => $ancestor, 'children' => []],
                        'post' => $post,
                        'depth' => 0,
                        'showReplyForm' => false,
                    ])
                @endforeach
            </div>
        @endif

        @include('comments._comment', [
            'node' => $focusedNode,
            'post' => $post,
            'depth' => 0,
            'focusedId' => $comment->id,
        ])
    </div>
@endsection
