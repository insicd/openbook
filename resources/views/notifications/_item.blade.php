@php
    /** @var \App\Domain\Notifications\Notification $notification */
    $causedByActor = $notification->actor;
    $targetUrl = $notification->targetUrl();
    $actorUrl = $notification->actorProfileUrl();
    $compact = $compact ?? false;
    $rowClass = $compact ? 'ob-header-notification' : 'ob-notification';

    if (! $notification->isRead()) {
        $rowClass .= $compact ? ' ob-header-notification--unread' : ' ob-notification--unread';
    }

    $avatarStyle = $compact ? 'width:36px;height:36px;font-size:0.95rem' : 'width:40px;height:40px';
@endphp
<div class="{{ $rowClass }}" @if ($compact) data-notification-id="{{ $notification->id }}" @endif>
    @if ($targetUrl)
        <a href="{{ $targetUrl }}" class="ob-notification__stretch" tabindex="-1" aria-hidden="true"></a>
    @endif

    @if ($actorUrl)
        <a href="{{ $actorUrl }}" class="ob-notification__actor" aria-label="{{ $causedByActor?->displayName() }}">
            <x-avatar :actor="$causedByActor" style="{{ $avatarStyle }}" />
        </a>
    @else
        <x-avatar :actor="$causedByActor" style="{{ $avatarStyle }}" />
    @endif

    <div @if ($compact) class="ob-header-notification__body" @endif>
        <div>{!! $notification->messageHtml() !!}</div>
        <div class="ob-notification__time">{{ $notification->created_at->diffForHumans() }}</div>
    </div>

    @if (! $compact && $targetUrl)
        <a href="{{ $targetUrl }}" class="ob-btn ob-btn--ghost ob-notification__view">{{ __('openbook.notifications.view') }}</a>
    @endif
</div>
