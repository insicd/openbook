{{--
  Campanella della navbar: apre un pannello con le notifiche recenti invece
  di andare alla pagina completa (che resta nella sidebar sinistra).
  Il feed JSON (data-notifications-feed-url) aggiorna badge e elenco in live.
--}}
<div
    class="ob-header-dropdown"
    data-header-panel="notifications"
    data-notifications-empty="{{ __('openbook.notifications.empty') }}"
    data-notifications-index="{{ route('notifications.index') }}"
>
    <button
        type="button"
        class="ob-icon-btn"
        id="ob-notifications-toggle"
        aria-label="{{ __('openbook.nav.notifications') }}"
        aria-controls="ob-notifications-panel"
        aria-expanded="false"
        data-header-panel-toggle
        data-mark-read-url="{{ route('notifications.read') }}"
        data-notifications-feed-url="{{ route('notifications.feed') }}"
        data-notifications-poll-ms="60000"
    >
        <x-icon name="bell" />
        <span class="ob-nav__label">{{ __('openbook.nav.notifications') }}</span>
        @if (($unreadNotificationsCount ?? 0) > 0)
            <span class="ob-badge-dot" data-notifications-badge>{{ $unreadNotificationsCount > 9 ? '9+' : $unreadNotificationsCount }}</span>
        @endif
    </button>

    <div class="ob-header-dropdown__panel" id="ob-notifications-panel" role="region" aria-label="{{ __('openbook.notifications.title') }}" hidden data-header-panel-content>
        <div class="ob-header-dropdown__title">{{ __('openbook.notifications.title') }}</div>

        <div data-notifications-list>
            @forelse ($headerNotifications ?? [] as $notification)
                @php
                    $causedByActor = $notification->actor;
                    $causedByName = $causedByActor?->displayName() ?: __('openbook.notifications.someone');
                    $url = $notification->targetUrl();
                @endphp
                <a
                    href="{{ $url ?: route('notifications.index') }}"
                    class="ob-header-notification {{ $notification->isRead() ? '' : 'ob-header-notification--unread' }}"
                    data-notification-id="{{ $notification->id }}"
                >
                    <x-avatar :actor="$causedByActor" style="width:36px;height:36px;font-size:0.95rem" />
                    <div class="ob-header-notification__body">
                        <div>{{ __('openbook.notifications.messages.'.$notification->type, ['name' => $causedByName]) }}</div>
                        <div class="ob-notification__time">{{ $notification->created_at->diffForHumans() }}</div>
                    </div>
                </a>
            @empty
                <p class="ob-header-dropdown__empty">{{ __('openbook.notifications.empty') }}</p>
            @endforelse
        </div>

        <a href="{{ route('notifications.index') }}" class="ob-header-dropdown__footer">
            {{ __('openbook.notifications.view_all') }}
        </a>
    </div>
</div>
