@extends('layouts.app')

@section('title', __('openbook.notifications.title').' - '.config('app.name'))

@php
    $nextUrl = $notifications->hasMorePages() ? $notifications->nextPageUrl() : null;
@endphp

@section('content')
    <div class="ob-card">
        <h1>{{ __('openbook.notifications.title') }}</h1>

        <div
            id="ob-notification-list"
            data-infinite-scroll
            @if ($nextUrl) data-next-url="{{ $nextUrl }}" @endif
            data-loading-label="{{ __('openbook.infinite_scroll.loading') }}"
            data-end-label="{{ __('openbook.infinite_scroll.end') }}"
            data-error-label="{{ __('openbook.infinite_scroll.error') }}"
        >
            @forelse ($notifications as $notification)
                @include('notifications._row', ['notification' => $notification])
            @empty
                <div class="ob-empty-state">
                    <p>{{ __('openbook.notifications.empty') }}</p>
                </div>
            @endforelse
        </div>

        @if ($notifications->hasPages())
            <noscript>
                <div class="ob-pagination">
                    {{ $notifications->onEachSide(1)->links() }}
                </div>
            </noscript>
        @endif
    </div>
@endsection
