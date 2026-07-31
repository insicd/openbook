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

    @php
        $nextUrl = $suggestedActors->hasMorePages() ? $suggestedActors->nextPageUrl() : null;
    @endphp

    <div class="ob-card ob-side-widget">
        <div
            id="ob-discover-list"
            data-infinite-scroll
            @if ($nextUrl) data-next-url="{{ $nextUrl }}" @endif
            data-loading-label="{{ __('openbook.world.infinite_scroll.loading') }}"
            data-end-label="{{ __('openbook.world.infinite_scroll.end') }}"
            data-error-label="{{ __('openbook.world.infinite_scroll.error') }}"
        >
            @forelse ($suggestedActors as $rowActor)
                @include('world._suggestion', ['rowActor' => $rowActor])
            @empty
                <div class="ob-empty-state">
                    <p>{{ __('openbook.world.discover_empty') }}</p>
                </div>
            @endforelse
        </div>

        @if ($suggestedActors->hasPages())
            <noscript>
                <div class="ob-pagination">
                    {{ $suggestedActors->onEachSide(1)->links() }}
                </div>
            </noscript>
        @endif
    </div>
@endsection
