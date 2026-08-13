<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='20' fill='%231877f2'/><text x='50' y='68' font-size='56' text-anchor='middle' fill='white' font-family='system-ui,sans-serif' font-weight='700'>O</text></svg>">
    <title>@yield('title', config('app.name'))</title>
    <link rel="stylesheet" href="{{ \App\Support\Assets::url('assets/css/app.css') }}">
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
                <div class="ob-header__center">
                    <a
                        href="{{ route('feed.index') }}#ob-composer"
                        class="ob-compose-btn ob-compose-btn--header{{ ($isHomeFeed ?? false) ? '' : ' is-visible' }}"
                        id="ob-compose-header"
                        data-compose-trigger
                        data-compose-home="{{ ($isHomeFeed ?? false) ? '1' : '0' }}"
                        aria-label="{{ __('openbook.nav.new_post') }}"
                        title="{{ __('openbook.nav.new_post') }}"
                        aria-haspopup="dialog"
                        aria-controls="ob-compose-modal"
                        @if ($isHomeFeed ?? false)
                            aria-hidden="true"
                            tabindex="-1"
                        @else
                            aria-hidden="false"
                        @endif
                    >
                        <x-icon name="plus" />
                    </a>
                </div>

                <div class="ob-header__end">
                    <button type="button" class="ob-icon-btn ob-nav-toggle" id="ob-nav-toggle"
                        aria-label="Altre opzioni" aria-controls="ob-nav-icons" aria-expanded="false">
                        <x-icon name="more" />
                    </button>

                    <nav class="ob-nav ob-nav--icons" id="ob-nav-icons" aria-label="Navigazione principale">
                        <a href="{{ route('feed.index') }}" class="ob-icon-btn" aria-label="{{ __('openbook.nav.home') }}">
                            <x-icon name="home" /><span class="ob-nav__label">{{ __('openbook.nav.home') }}</span>
                        </a>
                        @include('partials.header-search')
                        @include('partials.header-notifications')
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
            <a href="{{ route('instance.rules') }}">{{ __('openbook.footer.rules') }}</a> &middot;
            <a href="{{ route('instance.privacy') }}">{{ __('openbook.footer.privacy') }}</a> &middot;
            <a href="{{ config('openbook.homepage') }}" target="_blank">Openbook</a> v{{ config('openbook.version') }} &middot;
            {{ __('openbook.footer.license') }}
        </p>
    </footer>

    <div class="ob-lightbox" id="ob-lightbox" role="dialog" aria-modal="true" aria-label="{{ __('openbook.lightbox.label') }}" hidden>
        <button type="button" class="ob-lightbox__close" id="ob-lightbox-close" aria-label="{{ __('openbook.lightbox.close') }}">
            <x-icon name="close" />
        </button>
        <button type="button" class="ob-lightbox__nav ob-lightbox__nav--prev" id="ob-lightbox-prev" aria-label="{{ __('openbook.lightbox.previous') }}" hidden>&lsaquo;</button>
        <img class="ob-lightbox__img" id="ob-lightbox-img" src="" alt="">
        <button type="button" class="ob-lightbox__nav ob-lightbox__nav--next" id="ob-lightbox-next" aria-label="{{ __('openbook.lightbox.next') }}" hidden>&rsaquo;</button>
    </div>
    <script src="{{ \App\Support\Assets::url('assets/js/lightbox.js') }}" defer></script>
    <script src="{{ \App\Support\Assets::url('assets/js/infinite-scroll.js') }}" defer></script>
    <script src="{{ \App\Support\Assets::url('assets/js/post-menu.js') }}" defer></script>
    <script src="{{ \App\Support\Assets::url('assets/js/like.js') }}" defer></script>
    <script src="{{ \App\Support\Assets::url('assets/js/announce.js') }}" defer></script>
    <script src="{{ \App\Support\Assets::url('assets/js/header-panels.js') }}" defer></script>
    <script src="{{ \App\Support\Assets::url('assets/js/notifications-live.js') }}" defer></script>
    <script src="{{ \App\Support\Assets::url('assets/js/compose-shortcut.js') }}" defer></script>
    <script src="{{ \App\Support\Assets::url('assets/js/composer.js') }}" defer></script>

    @auth
        <div
            id="ob-emoji-i18n"
            hidden
            data-search="{{ __('openbook.emoji.search') }}"
            data-recent="{{ __('openbook.emoji.recent') }}"
            data-empty="{{ __('openbook.emoji.empty') }}"
            data-close="{{ __('openbook.emoji.close') }}"
            data-categories='@json(__('openbook.emoji.categories'))'
        ></div>
        <script src="{{ \App\Support\Assets::url('assets/js/emoji-data.js') }}" defer></script>
        <script src="{{ \App\Support\Assets::url('assets/js/emoji-picker.js') }}" defer></script>

        <div
            id="ob-mention-suggest"
            hidden
            data-url="{{ route('mentions.suggest') }}"
            data-label="{{ __('openbook.composer.mention_suggest_label') }}"
            data-empty="{{ __('openbook.composer.mention_suggest_empty') }}"
        ></div>
        <script src="{{ \App\Support\Assets::url('assets/js/mention-autocomplete.js') }}" defer></script>

        <div
            id="ob-search-suggest"
            hidden
            data-url="{{ route('search.suggest') }}"
            data-label="{{ __('openbook.search.suggest_label') }}"
            data-empty="{{ __('openbook.search.suggest_empty') }}"
            data-people="{{ __('openbook.search.people') }}"
            data-hashtags="{{ __('openbook.search.hashtags') }}"
            data-min-length="{{ (int) config('openbook.search.suggest_min_length', 2) }}"
        ></div>
        <script src="{{ \App\Support\Assets::url('assets/js/search-suggest.js') }}" defer></script>

        <a
            href="{{ route('feed.index') }}#ob-composer"
            class="ob-compose-btn ob-compose-btn--fab{{ ($isHomeFeed ?? false) ? '' : ' is-visible' }}"
            id="ob-compose-fab"
            data-compose-trigger
            data-compose-home="{{ ($isHomeFeed ?? false) ? '1' : '0' }}"
            aria-label="{{ __('openbook.nav.new_post') }}"
            title="{{ __('openbook.nav.new_post') }}"
            aria-haspopup="dialog"
            aria-controls="ob-compose-modal"
            @if ($isHomeFeed ?? false)
                aria-hidden="true"
                tabindex="-1"
            @else
                aria-hidden="false"
            @endif
        >
            <x-icon name="plus" />
        </a>

        @include('partials.compose-modal')

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

    {{-- Reset del backoff della pagina di attesa DB dopo un caricamento riuscito. --}}
    <script>
        try { sessionStorage.removeItem('ob.dbBusy'); } catch (e) {}
    </script>
</body>
</html>
