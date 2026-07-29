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
                <div class="ob-header__end">
                    <button type="button" class="ob-icon-btn ob-nav-toggle" id="ob-nav-toggle"
                        aria-label="Altre opzioni" aria-controls="ob-nav-icons" aria-expanded="false">
                        <x-icon name="more" />
                    </button>

                    <nav class="ob-nav ob-nav--icons" id="ob-nav-icons" aria-label="Navigazione principale">
                        <a href="{{ route('feed.index') }}" class="ob-icon-btn" aria-label="{{ __('openbook.nav.home') }}">
                            <x-icon name="home" /><span class="ob-nav__label">{{ __('openbook.nav.home') }}</span>
                        </a>
                        <a href="{{ route('search.create') }}" class="ob-icon-btn" aria-label="{{ __('openbook.nav.search') }}">
                            <x-icon name="search" /><span class="ob-nav__label">{{ __('openbook.nav.search') }}</span>
                        </a>
                        <a href="{{ route('notifications.index') }}" class="ob-icon-btn" aria-label="{{ __('openbook.nav.notifications') }}">
                            <x-icon name="bell" /><span class="ob-nav__label">{{ __('openbook.nav.notifications') }}</span>
                            @if (($unreadNotificationsCount ?? 0) > 0)
                                <span class="ob-badge-dot">{{ $unreadNotificationsCount > 9 ? '9+' : $unreadNotificationsCount }}</span>
                            @endif
                        </a>
                        <a href="{{ route('profile.show', auth()->user()->username) }}" class="ob-header__avatar-link" aria-label="{{ __('openbook.nav.profile') }}">
                            <x-avatar :user="auth()->user()" style="width:36px;height:36px;font-size:0.95rem" />
                            <span class="ob-nav__label">{{ __('openbook.nav.profile') }}</span>
                        </a>
                        <a href="{{ route('settings.edit') }}" class="ob-icon-btn" aria-label="{{ __('openbook.nav.settings') }}">
                            <x-icon name="settings" /><span class="ob-nav__label">{{ __('openbook.nav.settings') }}</span>
                        </a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="ob-icon-btn" aria-label="{{ __('openbook.nav.logout') }}">
                                <x-icon name="logout" /><span class="ob-nav__label">{{ __('openbook.nav.logout') }}</span>
                            </button>
                        </form>
                    </nav>
                </div>
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
        <p>
            {{ config('app.name') }} &middot;
            <a href="{{ config('openbook.homepage') }}">Openbook</a> v{{ config('openbook.version') }} &middot;
            {{ __('openbook.footer.license') }}
        </p>
    </footer>

    @auth
        <div class="ob-sidebar-overlay" id="ob-sidebar-overlay" hidden></div>
        <script>
            (function () {
                var overlay = document.getElementById('ob-sidebar-overlay');
                if (!overlay) {
                    return;
                }

                var panels = [
                    { toggle: document.getElementById('ob-sidebar-toggle'), panel: document.getElementById('ob-sidebar-left') },
                    { toggle: document.getElementById('ob-nav-toggle'), panel: document.getElementById('ob-nav-icons') },
                ].filter(function (entry) {
                    return entry.toggle && entry.panel;
                });

                function closeAll() {
                    panels.forEach(function (entry) {
                        entry.panel.classList.remove('is-open');
                        entry.toggle.setAttribute('aria-expanded', 'false');
                    });
                    overlay.hidden = true;
                }

                function open(entry) {
                    closeAll();
                    entry.panel.classList.add('is-open');
                    entry.toggle.setAttribute('aria-expanded', 'true');
                    overlay.hidden = false;
                }

                panels.forEach(function (entry) {
                    entry.toggle.addEventListener('click', function () {
                        if (entry.panel.classList.contains('is-open')) {
                            closeAll();
                        } else {
                            open(entry);
                        }
                    });
                });

                overlay.addEventListener('click', closeAll);
                document.addEventListener('keydown', function (event) {
                    if (event.key === 'Escape') {
                        closeAll();
                    }
                });
            })();
        </script>
    @endauth
</body>
</html>
