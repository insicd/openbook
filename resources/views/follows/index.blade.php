@extends('layouts.app')

@php
    $ownerName = $owner->displayName();
    $pageTitle = $type === 'followers'
        ? __('openbook.follows.followers_title', ['name' => $ownerName])
        : __('openbook.follows.following_title', ['name' => $ownerName]);
@endphp

@section('title', $pageTitle.' - '.config('app.name'))

@section('content')
    <div class="ob-card">
        <p class="ob-field__help" style="margin-bottom:0.5rem">
            <a href="{{ $owner->profileUrl() }}">&larr; {{ __('openbook.follows.back_to_profile') }}</a>
        </p>
        <h1 style="margin-bottom:0">{{ $pageTitle }}</h1>
    </div>

    <div class="ob-card">
        @forelse ($actors as $rowActor)
            @php
                $isSelf = $viewerActor && $viewerActor->id === $rowActor->id;
                $status = $statusMap[$rowActor->id] ?? null;
            @endphp
            <div class="ob-suggestion">
                <a href="{{ $rowActor->profileUrl() }}" class="ob-mini-profile__link">
                    <x-avatar :actor="$rowActor" style="width:40px;height:40px" />
                    <div>
                        <div class="ob-post__author">{{ $rowActor->displayName() }}</div>
                        <div class="ob-post__handle">{{ '@'.$rowActor->handle() }}</div>
                    </div>
                </a>

                @auth
                    @unless ($isSelf)
                        @if ($status['following'] ?? false)
                            <form method="POST" action="{{ route('actors.unfollow', $rowActor) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="ob-btn ob-btn--ghost ob-btn--small">{{ __('openbook.follow.unfollow') }}</button>
                            </form>
                        @elseif ($status['pending'] ?? false)
                            <form method="POST" action="{{ route('actors.unfollow', $rowActor) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="ob-btn ob-btn--ghost ob-btn--small">{{ __('openbook.follow.cancel_request') }}</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('actors.follow', $rowActor) }}">
                                @csrf
                                <button type="submit" class="ob-btn ob-btn--ghost ob-btn--small">{{ __('openbook.follow.follow') }}</button>
                            </form>
                        @endif
                    @endunless
                @endauth
            </div>
        @empty
            <div class="ob-empty-state">
                <p>{{ $type === 'followers' ? __('openbook.follows.empty_followers') : __('openbook.follows.empty_following') }}</p>
            </div>
        @endforelse
    </div>

    @if ($actors->hasPages())
        <div class="ob-pagination">
            {{ $actors->onEachSide(1)->links() }}
        </div>
    @endif
@endsection
