@extends('layouts.app')

@section('title', config('app.name').' - '.__('openbook.app.tagline'))

@section('content')
    <section class="ob-hero">
        <h1>{{ __('openbook.home.hero_title', ['app' => config('app.name')]) }}</h1>
        <p>{{ __('openbook.home.hero_subtitle') }}</p>
        <div class="ob-hero-actions">
            <a href="{{ route('register') }}" class="ob-btn ob-btn--primary">{{ __('openbook.home.cta_register') }}</a>
            <a href="{{ route('login') }}" class="ob-btn ob-btn--ghost">{{ __('openbook.home.cta_login') }}</a>
        </div>
    </section>

    <div class="ob-card">
        <h2>{{ __('openbook.home.instance_about_title') }}</h2>
        <p>
            <strong>{{ config('app.name') }}</strong>
            &middot; {{ config('openbook.domain') }}
        </p>
        <p>{{ __('openbook.app.tagline') }}</p>
    </div>
@endsection
