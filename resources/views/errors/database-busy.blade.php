<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('openbook.errors.database_busy_title') }} - {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ \App\Support\Assets::url('assets/css/app.css') }}">
</head>
<body class="ob-db-busy">
    <main class="ob-db-busy__panel" role="status" aria-live="polite">
        <div class="ob-db-busy__spinner" aria-hidden="true"></div>

        <p class="ob-db-busy__brand">{{ config('app.name') }}</p>
        <h1 class="ob-db-busy__title">{{ __('openbook.errors.database_busy_title') }}</h1>
        <p class="ob-db-busy__body" id="ob-db-busy-status">
            {{ $retryUrl
                ? __('openbook.errors.database_busy_body')
                : __('openbook.errors.database_busy_body_manual') }}
        </p>

        @if ($retryUrl)
            <p class="ob-db-busy__hint" id="ob-db-busy-hint">
                {{ __('openbook.errors.database_busy_auto') }}
            </p>
            <p class="ob-db-busy__actions">
                <a href="{{ $retryUrl }}" class="ob-btn ob-btn--primary" id="ob-db-busy-retry">
                    {{ __('openbook.errors.database_busy_retry') }}
                </a>
            </p>
        @else
            <p class="ob-db-busy__actions">
                <a href="{{ url('/') }}" class="ob-btn ob-btn--primary">
                    {{ __('openbook.errors.database_busy_back') }}
                </a>
            </p>
        @endif
    </main>

    @if ($retryUrl)
        <script>
            (function () {
                var url = @json($retryUrl);
                var key = 'ob.dbBusy';
                var now = Date.now();
                var prev = null;

                try {
                    prev = JSON.parse(sessionStorage.getItem(key) || 'null');
                } catch (e) {
                    prev = null;
                }

                // Nuova "ondata" se l'ultimo tentativo e' vecchio: altrimenti
                // aumenta il backoff cosi' non martelliamo MySQL.
                var attempt = (!prev || (now - prev.t) > 60000) ? 1 : (prev.n + 1);

                try {
                    sessionStorage.setItem(key, JSON.stringify({ n: attempt, t: now }));
                } catch (e) {}

                var delay = Math.min(Math.round(900 * Math.pow(1.45, attempt - 1)), 10000);
                var status = document.getElementById('ob-db-busy-status');
                var hint = document.getElementById('ob-db-busy-hint');

                if (attempt >= 4 && status) {
                    status.textContent = @json(__('openbook.errors.database_busy_body_slow'));
                }

                if (hint) {
                    hint.textContent = @json(__('openbook.errors.database_busy_auto'));
                }

                window.setTimeout(function () {
                    window.location.replace(url);
                }, delay);
            })();
        </script>
    @endif
</body>
</html>
