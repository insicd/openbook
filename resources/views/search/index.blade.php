@extends('layouts.app')

@section('title', __('openbook.search.title').' - '.config('app.name'))

@section('content')
    <div class="ob-card">
        <h1>{{ __('openbook.search.title') }}</h1>
        <p class="ob-field__help">{{ __('openbook.search.help') }}</p>

        <form method="GET" action="{{ route('search.create') }}" novalidate>
            <div class="ob-field">
                <label for="search-q">{{ __('openbook.search.placeholder') }}</label>
                <input type="search" id="search-q" name="q" value="{{ old('q', $query ?? '') }}"
                       placeholder="{{ __('openbook.search.placeholder') }}"
                       required autofocus
                       minlength="{{ (int) config('openbook.search.min_length', 2) }}"
                       @if ($errors->has('q')) aria-invalid="true" @endif>
                @error('q')
                    <p class="ob-field__error">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="ob-btn ob-btn--primary">{{ __('openbook.search.submit') }}</button>
        </form>
    </div>

    @if ($results !== null)
        @php
            $hasAny = $results['people']->isNotEmpty()
                || $results['posts']->isNotEmpty()
                || $results['comments']->isNotEmpty()
                || $results['hashtags']->isNotEmpty();
        @endphp

        @unless ($hasAny)
            <div class="ob-card">
                <div class="ob-empty-state">
                    <p>{{ __('openbook.search.empty', ['query' => $query]) }}</p>
                </div>
            </div>
        @endunless

        @if ($results['people']->isNotEmpty())
            <div class="ob-card">
                <h2 class="ob-side-widget__title">{{ __('openbook.search.people') }}</h2>
                @foreach ($results['people'] as $person)
                    @php
                        $personName = $person->profile?->display_name ?: $person->username;
                    @endphp
                    <div class="ob-suggestion">
                        <a href="{{ route('profile.show', $person->username) }}" class="ob-mini-profile__link">
                            <x-avatar :user="$person" style="width:40px;height:40px" />
                            <div>
                                <div class="ob-post__author">{{ $personName }}</div>
                                <div class="ob-post__handle">{{ '@'.$person->username.'@'.config('openbook.domain') }}</div>
                                @if ($person->profile?->bio)
                                    <div class="ob-field__help">{{ \App\Domain\Posts\PostBodyRenderer::render(\Illuminate\Support\Str::limit($person->profile->bio, 120)) }}</div>
                                @endif
                            </div>
                        </a>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($results['hashtags']->isNotEmpty())
            <div class="ob-card">
                <h2 class="ob-side-widget__title">{{ __('openbook.search.hashtags') }}</h2>
                <ul class="ob-hashtag-list">
                    @foreach ($results['hashtags'] as $hashtag)
                        <li>
                            <a href="{{ route('hashtags.show', $hashtag->name) }}">#{{ $hashtag->name }}</a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($results['posts']->isNotEmpty())
            <div class="ob-card">
                <h2 class="ob-side-widget__title">{{ __('openbook.search.posts') }}</h2>
            </div>
            @foreach ($results['posts'] as $post)
                @include('posts._card', ['post' => $post])
            @endforeach
        @endif

        @if ($results['comments']->isNotEmpty())
            <div class="ob-card">
                <h2 class="ob-side-widget__title">{{ __('openbook.search.comments') }}</h2>
                @foreach ($results['comments'] as $comment)
                    @php
                        $commentAuthor = $comment->actor;
                        $commentName = $commentAuthor?->displayName();
                        $parentPost = $comment->post;
                    @endphp
                    <div class="ob-search-comment">
                        <div class="ob-post__header">
                            <x-avatar :actor="$commentAuthor" style="width:36px;height:36px;font-size:1rem" />
                            <div class="ob-post__meta">
                                @if ($commentAuthor)
                                    <a href="{{ $commentAuthor->profileUrl() }}" class="ob-post__author">{{ $commentName }}</a>
                                @endif
                                <div class="ob-post__time">
                                    @if ($parentPost)
                                        <a href="{{ route('posts.show', $parentPost) }}#commento-{{ $comment->id }}">
                                            {{ $comment->created_at->diffForHumans() }}
                                        </a>
                                    @else
                                        {{ $comment->created_at->diffForHumans() }}
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="ob-comment__body">{{ \App\Domain\Posts\PostBodyRenderer::render($comment->body) }}</div>
                        @if ($parentPost)
                            <p class="ob-field__help">
                                <a href="{{ route('posts.show', $parentPost) }}#commento-{{ $comment->id }}">
                                    {{ __('openbook.search.view_in_post') }}
                                </a>
                            </p>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    @endif
@endsection
