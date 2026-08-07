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
