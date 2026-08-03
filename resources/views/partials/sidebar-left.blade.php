@php
    $currentUser = auth()->user();
    $displayName = $currentUser->profile?->display_name ?: $currentUser->username;
    $handle = '@'.$currentUser->username;
@endphp

<div class="ob-card ob-mini-profile">
    <a href="{{ route('profile.show', $currentUser->username) }}" class="ob-mini-profile__link">
        <x-avatar :user="$currentUser" style="width:56px;height:56px;font-size:1.4rem" />
        <div>
            <div class="ob-mini-profile__name">{{ $displayName }}</div>
            <div class="ob-mini-profile__handle">{{ $handle }}</div>
        </div>
    </a>
</div>

<nav class="ob-card ob-side-nav" aria-label="Menu principale">
    <a href="{{ route('feed.index') }}" class="ob-side-nav__link {{ request()->routeIs('feed.index') ? 'is-active' : '' }}">
        <x-icon name="home" /> {{ __('openbook.nav.home') }}
    </a>
    <a href="{{ route('world.index') }}" class="ob-side-nav__link {{ request()->routeIs('world.*') ? 'is-active' : '' }}">
        <x-icon name="globe" /> {{ __('openbook.nav.world') }}
    </a>
    <a href="{{ route('communities.index') }}" class="ob-side-nav__link {{ request()->routeIs('communities.*') ? 'is-active' : '' }}">
        <x-icon name="people" /> {{ __('openbook.nav.communities') }}
    </a>
    <a href="{{ route('notifications.index') }}" class="ob-side-nav__link {{ request()->routeIs('notifications.index') ? 'is-active' : '' }}" data-notifications-nav>
        <x-icon name="bell" /> {{ __('openbook.nav.notifications') }}
        @if (($unreadNotificationsCount ?? 0) > 0)
            <span class="ob-badge-count" data-notifications-badge>{{ $unreadNotificationsCount > 9 ? '9+' : $unreadNotificationsCount }}</span>
        @endif
    </a>
    <a href="{{ route('profile.show', $currentUser->username) }}" class="ob-side-nav__link {{ request()->routeIs('profile.show') && ($profileUser ?? null)?->id === $currentUser->id ? 'is-active' : '' }}">
        <x-icon name="user" /> {{ __('openbook.nav.profile') }}
    </a>
    @if ($currentUser->isStaff())
        <a href="{{ route('admin.dashboard') }}" class="ob-side-nav__link {{ request()->routeIs('admin.*') ? 'is-active' : '' }}">
            <x-icon name="settings" /> {{ __('openbook.nav.admin') }}
        </a>
    @endif
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="ob-side-nav__link ob-side-nav__link--button">
            <x-icon name="logout" /> {{ __('openbook.nav.logout') }}
        </button>
    </form>
</nav>

<p class="ob-sidebar-footer">
    <a href="{{ config('openbook.homepage') }}">Openbook</a>
    &middot;
    <a href="{{ route('instance.rules') }}">{{ __('openbook.footer.rules') }}</a>
    &middot;
    <a href="{{ route('instance.privacy') }}">{{ __('openbook.footer.privacy') }}</a>
    &middot;
    <a href="{{ config('openbook.homepage') }}">Openbook</a> v{{ config('openbook.version') }}
</p>
