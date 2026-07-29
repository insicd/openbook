<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('openbook.errors.database_busy_title') }} - {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ \App\Support\Assets::url('assets/css/app.css') }}">
</head>
<body>
    <main class="ob-main" style="max-width:40rem;margin:3rem auto;padding:0 1rem">
        <div class="ob-card">
            <h1>{{ __('openbook.errors.database_busy_title') }}</h1>
            <p>{{ __('openbook.errors.database_busy_body') }}</p>

            @if ($retryUrl)
                <p style="margin-top:1rem">
                    <a href="{{ $retryUrl }}" class="ob-btn ob-btn--primary">{{ __('openbook.errors.database_busy_retry') }}</a>
                </p>
            @else
                <p style="margin-top:1rem">
                    <a href="{{ url('/') }}" class="ob-btn ob-btn--primary">{{ __('openbook.errors.database_busy_back') }}</a>
                </p>
            @endif
        </div>
    </main>
</body>
</html>
