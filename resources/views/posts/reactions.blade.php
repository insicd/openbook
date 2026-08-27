@extends('layouts.app')

@section('title', $title.' - '.config('app.name'))

@section('content')
    <div class="ob-card">
        <p class="ob-field__help" style="margin-bottom:0.5rem">
            <a href="{{ route('posts.show', $post) }}">&larr; {{ __('openbook.posts.back_to_post') }}</a>
        </p>
        <h1 style="margin-bottom:0">{{ $title }}</h1>
        @if ($total > 0)
            <p class="ob-field__help" style="margin-top:0.5rem;margin-bottom:0">{{ $total }}</p>
        @endif
    </div>

    <div class="ob-card">
        @forelse ($actors as $rowActor)
            <div class="ob-suggestion">
                <a href="{{ $rowActor->profileUrl() }}" class="ob-mini-profile__link">
                    <x-avatar :actor="$rowActor" style="width:40px;height:40px" />
                    <div>
                        <div class="ob-post__author">{{ $rowActor->displayName() }}</div>
                        <div class="ob-post__handle">{{ '@'.$rowActor->handle() }}</div>
                    </div>
                </a>
            </div>
        @empty
            <div class="ob-empty-state">
                <p>{{ __('openbook.actions.reaction_list_empty') }}</p>
            </div>
        @endforelse

        @if ($remaining > 0)
            <p class="ob-field__help" style="margin:0.75rem 0 0">
                {{ __('openbook.actions.reaction_list_more', ['count' => $remaining]) }}
            </p>
        @endif
    </div>
@endsection
