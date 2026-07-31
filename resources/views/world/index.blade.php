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
                @include('world._suggestion', ['rowActor' => $rowActor])
            @endforeach
            @if ($suggestedActorsHasMore ?? false)
                <a href="{{ route('world.discover') }}" class="ob-side-widget__more">{{ __('openbook.world.suggested_more') }}</a>
            @endif
        </div>
    @endif

    @include('posts._feed', ['posts' => $posts, 'emptyMessage' => __('openbook.world.empty')])
@endsection
