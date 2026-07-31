@extends('layouts.app')

@section('title', __('openbook.world.discover_title').' - '.config('app.name'))

@section('content')
    <div class="ob-card">
        <p class="ob-field__help" style="margin-bottom:0.75rem">
            <a href="{{ route('world.index') }}">{{ __('openbook.world.back_to_world') }}</a>
        </p>
        <h1 style="margin-bottom:0.25rem">{{ __('openbook.world.discover_title') }}</h1>
        <p class="ob-field__help">{{ __('openbook.world.discover_subtitle') }}</p>
    </div>

    <div class="ob-card ob-side-widget">
        @if ($suggestedActors->isEmpty())
            <div class="ob-empty-state">
                <p>{{ __('openbook.world.discover_empty') }}</p>
            </div>
        @else
            @foreach ($suggestedActors as $rowActor)
                @include('world._suggestion', ['rowActor' => $rowActor])
            @endforeach
            {{ $suggestedActors->links() }}
        @endif
    </div>
@endsection
