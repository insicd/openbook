@extends('layouts.app')

@php
    $displayName = $post->actor->displayName();
@endphp

@section('title', __('openbook.posts.page_title', ['name' => $displayName]).' - '.config('app.name'))

@section('content')
    @include('posts._card', ['post' => $post, 'linkToPost' => false])

    <div class="ob-card" id="commenta">
        @auth
            <form method="POST" action="{{ route('comments.store', $post) }}">
                @csrf
                <div class="ob-field">
                    <label for="comment-body">{{ __('openbook.comments.new_label') }}</label>
                    <textarea id="comment-body" name="body" rows="3" required maxlength="{{ config('openbook.comments.max_length', 2000) }}" data-mention-autocomplete>{{ old('body') }}</textarea>
                    <p class="ob-field__help">{{ __('openbook.composer.markdown_help') }}</p>
                    <div class="ob-emoji-toolbar">
                        <x-emoji-trigger target="comment-body" />
                    </div>
                </div>
                <button type="submit" class="ob-btn ob-btn--primary">{{ __('openbook.actions.comment_submit') }}</button>
            </form>
        @else
            <p><a href="{{ route('login') }}">{{ __('openbook.comments.login_to_comment') }}</a></p>
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
