@extends('layouts.app')

@section('title', __('openbook.notifications.title').' - '.config('app.name'))

@section('content')
    <div class="ob-card">
        <h1>{{ __('openbook.notifications.title') }}</h1>

        @forelse ($notifications as $notification)
            @php
                $causedByActor = $notification->actor;
                $url = $notification->targetUrl();
            @endphp
            <div class="ob-notification {{ $notification->isRead() ? '' : 'ob-notification--unread' }}">
                <x-avatar :actor="$causedByActor" style="width:40px;height:40px" />
                <div>
                    <div>{{ $notification->message() }}</div>
                    <div class="ob-notification__time">{{ $notification->created_at->diffForHumans() }}</div>
                </div>
                @if ($url)
                    <a href="{{ $url }}" class="ob-btn ob-btn--ghost" style="margin-left:auto">{{ __('openbook.notifications.view') }}</a>
                @endif
            </div>
        @empty
            <div class="ob-empty-state">
                <p>{{ __('openbook.notifications.empty') }}</p>
            </div>
        @endforelse

        @if ($notifications->hasPages())
            <div class="ob-pagination">
                {{ $notifications->onEachSide(1)->links() }}
            </div>
        @endif
    </div>
@endsection
