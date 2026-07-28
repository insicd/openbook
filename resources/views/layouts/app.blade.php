<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    <link rel="stylesheet" href="{{ asset('assets/css/app.css') }}">
    @stack('head')
</head>
<body>
    <a href="#ob-content" class="ob-skip-link">Vai al contenuto principale</a>

    <header class="ob-header">
        <div class="ob-header__inner">
            <div class="ob-header__start">
                @auth
                    <button type="button" class="ob-icon-btn ob-sidebar-toggle" id="ob-sidebar-toggle"
                        aria-label="Apri il menu" aria-controls="ob-sidebar-left" aria-expanded="false">
                        <x-icon name="menu" />
                    </button>
                @endauth
                <a href="{{ route('home') }}" class="ob-brand">{{ config('app.name') }}</a>
            </div>

            @auth
                <nav class="ob-nav ob-nav--icons" aria-label="Navigazione principale">
                    <a href="{{ route('feed.index') }}" class="ob-icon-btn" aria-label="{{ __('openbook.nav.home') }}">
                        <x-icon name="home" />
                    </a>
                    <a href="{{ route('search.create') }}" class="ob-icon-btn" aria-label="{{ __('openbook.nav.search') }}">
                        <x-icon name="search" />
                    </a>
                    <a href="{{ route('notifications.index') }}" class="ob-icon-btn" aria-label="{{ __('openbook.nav.notifications') }}">
                        <x-icon name="bell" />
                        @if (($unreadNotificationsCount ?? 0) > 0)
                            <span class="ob-badge-dot">{{ $unreadNotificationsCount > 9 ? '9+' : $unreadNotificationsCount }}</span>
                        @endif
                    </a>
                    <a href="{{ route('profile.show', auth()->user()->username) }}" class="ob-header__avatar-link" aria-label="{{ __('openbook.nav.profile') }}">
                        <x-avatar :user="auth()->user()" style="width:36px;height:36px;font-size:0.95rem" />
                    </a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="ob-icon-btn" aria-label="{{ __('openbook.nav.logout') }}">
                            <x-icon name="logout" />
                        </button>
                    </form>
                </nav>
            @else
                <nav class="ob-nav" aria-label="Navigazione principale">
                    <a href="{{ route('login') }}" class="ob-nav__link">{{ __('openbook.nav.login') }}</a>
                    <a href="{{ route('register') }}" class="ob-btn ob-btn--primary">{{ __('openbook.nav.register') }}</a>
                </nav>
            @endauth
        </div>
    </header>

    <div class="ob-shell {{ auth()->check() ? 'ob-shell--with-sidebars' : '' }}">
        @auth
            <aside class="ob-sidebar ob-sidebar--left" id="ob-sidebar-left">
                @include('partials.sidebar-left')
            </aside>
        @endauth

        <main id="ob-content" class="ob-main">
            @if (session('status'))
                <div class="ob-alert ob-alert--success" role="status">{{ session('status') }}</div>
            @endif

            @if ($errors->any())
                <div class="ob-alert ob-alert--error" role="alert">
                    <strong>Controlla i dati inseriti:</strong>
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @yield('content')
        </main>

        @auth
            <aside class="ob-sidebar ob-sidebar--right">
                @include('partials.sidebar-right')
            </aside>
        @endauth
    </div>

    <footer class="ob-footer">
        <p>{{ config('app.name') }} &middot; software libero sotto licenza AGPL-3.0-or-later</p>
    </footer>

    @auth
        <div class="ob-sidebar-overlay" id="ob-sidebar-overlay" hidden></div>
        <script>
            (function () {
                var toggle = document.getElementById('ob-sidebar-toggle');
                var sidebar = document.getElementById('ob-sidebar-left');
                var overlay = document.getElementById('ob-sidebar-overlay');
                if (!toggle || !sidebar || !overlay) {
                    return;
                }

                function closeSidebar() {
                    sidebar.classList.remove('is-open');
                    overlay.hidden = true;
                    toggle.setAttribute('aria-expanded', 'false');
                }

                function openSidebar() {
                    sidebar.classList.add('is-open');
                    overlay.hidden = false;
                    toggle.setAttribute('aria-expanded', 'true');
                }

                toggle.addEventListener('click', function () {
                    if (sidebar.classList.contains('is-open')) {
                        closeSidebar();
                    } else {
                        openSidebar();
                    }
                });

                overlay.addEventListener('click', closeSidebar);
            })();
        </script>
    @endauth
</body>
</html>
