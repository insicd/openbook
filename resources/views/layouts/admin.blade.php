<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='20' fill='%231877f2'/><text x='50' y='68' font-size='56' text-anchor='middle' fill='white' font-family='system-ui,sans-serif' font-weight='700'>O</text></svg>">
    <title>@yield('title', __('openbook.admin.title').' - '.config('app.name'))</title>
    <link rel="stylesheet" href="{{ \App\Support\Assets::url('assets/css/app.css') }}">
</head>
<body class="ob-admin-body">
    <a href="#ob-admin-content" class="ob-skip-link">Vai al contenuto principale</a>

    <header class="ob-header">
        <div class="ob-header__inner">
            <div class="ob-header__start">
                <a href="{{ route('admin.dashboard') }}" class="ob-brand">{{ config('app.name') }}</a>
                <span class="ob-admin-badge">{{ __('openbook.admin.badge') }}</span>
            </div>
            <nav class="ob-nav" aria-label="{{ __('openbook.admin.nav_aria') }}">
                <a href="{{ route('feed.index') }}" class="ob-nav__link">{{ __('openbook.admin.back_to_app') }}</a>
            </nav>
        </div>
    </header>

    <div class="ob-admin-shell">
        <aside class="ob-admin-nav" aria-label="{{ __('openbook.admin.nav_aria') }}">
            <a href="{{ route('admin.dashboard') }}" class="ob-side-nav__link {{ request()->routeIs('admin.dashboard') ? 'is-active' : '' }}">
                <x-icon name="home" /> {{ __('openbook.admin.nav.dashboard') }}
            </a>
            <a href="{{ route('admin.reports.index') }}" class="ob-side-nav__link {{ request()->routeIs('admin.reports.*') ? 'is-active' : '' }}">
                <x-icon name="flag" /> {{ __('openbook.admin.nav.reports') }}
            </a>
            <a href="{{ route('admin.users.index') }}" class="ob-side-nav__link {{ request()->routeIs('admin.users.*') ? 'is-active' : '' }}">
                <x-icon name="people" /> {{ __('openbook.admin.nav.users') }}
            </a>
            @can('administer')
                <a href="{{ route('admin.settings.edit') }}" class="ob-side-nav__link {{ request()->routeIs('admin.settings.*') ? 'is-active' : '' }}">
                    <x-icon name="settings" /> {{ __('openbook.admin.nav.settings') }}
                </a>
                <a href="{{ route('admin.domain_blocks.index') }}" class="ob-side-nav__link {{ request()->routeIs('admin.domain_blocks.*') ? 'is-active' : '' }}">
                    <x-icon name="globe" /> {{ __('openbook.admin.nav.domain_blocks') }}
                </a>
                <a href="{{ route('admin.queue.index') }}" class="ob-side-nav__link {{ request()->routeIs('admin.queue.*') ? 'is-active' : '' }}">
                    <x-icon name="warning" /> {{ __('openbook.admin.nav.queue') }}
                </a>
                <a href="{{ route('admin.audit.index') }}" class="ob-side-nav__link {{ request()->routeIs('admin.audit.*') ? 'is-active' : '' }}">
                    <x-icon name="info" /> {{ __('openbook.admin.nav.audit') }}
                </a>
            @endcan
        </aside>

        <main id="ob-admin-content" class="ob-admin-main">
            @if (session('status'))
                <div class="ob-alert ob-alert--success" role="status">{{ session('status') }}</div>
            @endif

            @if ($errors->any())
                <div class="ob-alert ob-alert--error" role="alert">
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @yield('content')
        </main>
    </div>
</body>
</html>
