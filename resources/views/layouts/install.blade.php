<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Installazione - Openbook')</title>
    <link rel="stylesheet" href="{{ \App\Support\Assets::url('assets/css/app.css') }}">
</head>
<body>
    <header class="ob-header">
        <div class="ob-header__inner">
            <span class="ob-brand">Openbook &middot; Installazione guidata</span>
        </div>
    </header>

    <main id="ob-content" class="ob-main">
        <div class="ob-narrow" style="max-width:640px">
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
        </div>
    </main>

    <footer class="ob-footer">
        <p><a href="{{ config('openbook.homepage') }}">Openbook</a> v{{ config('openbook.release_label') }} &middot; software libero sotto licenza AGPL-3.0-or-later</p>
    </footer>
</body>
</html>
