@extends('layouts.app')

@section('title', __('openbook.nav.world').' - '.config('app.name'))

@section('content')
    <div class="ob-card">
        <h1 style="margin-bottom:0.25rem">{{ __('openbook.world.title') }}</h1>
        <p class="ob-field__help">{{ __('openbook.world.subtitle') }}</p>
    </div>

    @if ($suggestedActors->isNotEmpty())
        <div class="ob-card ob-side-widget">
            <h2 class="ob-side-widget__title">{{ __('openbook.world.suggested_title') }}</h2>
            @foreach ($suggestedActors as $rowActor)
                <div class="ob-suggestion">
                    <a href="{{ $rowActor->profileUrl() }}" class="ob-mini-profile__link">
                        <x-avatar :actor="$rowActor" style="width:40px;height:40px" />
                        <div>
                            <div class="ob-post__author">{{ $rowActor->displayName() }}</div>
                            <div class="ob-post__handle">{{ '@'.$rowActor->handle() }}</div>
                        </div>
                    </a>
                    <form method="POST" action="{{ route('actors.follow', $rowActor) }}">
                        @csrf
                        <button type="submit" class="ob-btn ob-btn--ghost ob-btn--small">{{ __('openbook.follow.follow') }}</button>
                    </form>
                </div>
            @endforeach
        </div>
    @endif

    @include('posts._feed', ['posts' => $posts, 'emptyMessage' => __('openbook.world.empty')])
@endsection
