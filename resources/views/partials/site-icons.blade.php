@php
    $instanceIcons = app(\App\Application\Services\InstanceSettings::class);
    $customFavicon = $instanceIcons->faviconUrl();
@endphp
@if ($customFavicon)
    <link rel="icon" type="image/png" sizes="32x32" href="{{ $customFavicon }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ $instanceIcons->appleTouchIconUrl() }}">
    <link rel="manifest" href="{{ route('site.manifest') }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ $instanceIcons->siteName() }}">
    <meta name="theme-color" content="#1877f2">
@else
    <link rel="icon" type="image/svg+xml" href="{!! \App\Application\Services\InstanceSettings::DEFAULT_FAVICON_HREF !!}">
@endif
