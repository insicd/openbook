@extends('layouts.app')

@php
    $ownerName = $owner->displayName();
    $pageTitle = $pageTitle ?? ($type === 'followers'
        ? __('openbook.follows.followers_title', ['name' => $ownerName])
        : __('openbook.follows.following_title', ['name' => $ownerName]));
    $backUrl = $backUrl ?? $owner->profileUrl();
    $backLabel = $backLabel ?? __('openbook.follows.back_to_profile');
    $emptyMessage = $emptyMessage ?? ($type === 'followers'
        ? __('openbook.follows.empty_followers')
        : __('openbook.follows.empty_following'));
    $remoteMembers = $remoteMembers ?? collect();
    $nextUrl = $actors->hasMorePages() ? $actors->nextPageUrl() : null;
    $hasRemote = $remoteMembers->isNotEmpty();
@endphp

@section('title', $pageTitle.' - '.config('app.name'))

@section('content')
    <div class="ob-card">
        <p class="ob-field__help" style="margin-bottom:0.5rem">
            <a href="{{ $backUrl }}">&larr; {{ $backLabel }}</a>
        </p>
        <h1 style="margin-bottom:0">{{ $pageTitle }}</h1>
        @if ($hasRemote)
            <p class="ob-field__help" style="margin-top:0.75rem;margin-bottom:0">
                {{ ($remotePreviewIncomplete ?? false)
                    ? __('openbook.follows.remote_preview_incomplete')
                    : __('openbook.follows.remote_preview') }}
            </p>
        @endif
    </div>

    @if ($hasRemote)
        <div class="ob-card">
            @foreach ($remoteMembers as $rowActor)
                @include('follows._row', ['rowActor' => $rowActor])
            @endforeach
        </div>
    @endif

    <div class="ob-card">
        <div
            id="ob-follow-list"
            data-infinite-scroll
            @if ($nextUrl) data-next-url="{{ $nextUrl }}" @endif
            data-loading-label="{{ __('openbook.follows.infinite_scroll.loading') }}"
            data-end-label="{{ __('openbook.follows.infinite_scroll.end') }}"
            data-error-label="{{ __('openbook.follows.infinite_scroll.error') }}"
        >
            @forelse ($actors as $rowActor)
                @include('follows._row', ['rowActor' => $rowActor])
            @empty
                @unless ($hasRemote)
                    <div class="ob-empty-state">
                        <p>{{ $emptyMessage }}</p>
                    </div>
                @endunless
            @endforelse
        </div>

        @if ($actors->hasPages())
            <noscript>
                <div class="ob-pagination">
                    {{ $actors->onEachSide(1)->links() }}
                </div>
            </noscript>
        @endif
    </div>
@endsection
