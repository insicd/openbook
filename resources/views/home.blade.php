@extends('layouts.app')

@section('title', ($siteName ?? config('app.name')).' - '.($siteDescription !== '' ? $siteDescription : __('openbook.app.tagline')))

@push('head')
    @if (filled($homeBackgroundUrl ?? null))
        <style>
            body.ob-guest-home--has-bg {
                --ob-guest-home-image: url({{ \Illuminate\Support\Js::from($homeBackgroundUrl) }});
            }
        </style>
    @endif
@endpush

@section('content')
    <section class="ob-hero ob-hero--guest">
        @if (filled($instanceLogoUrl ?? null))
            <img class="ob-hero__logo" src="{{ $instanceLogoUrl }}" alt="" width="72" height="72">
        @endif
        <h1>{{ $siteName ?? config('app.name') }}</h1>
        <p class="ob-hero__meta">{{ config('openbook.domain') }}</p>
        @if (($siteDescription ?? '') !== '')
            <p class="ob-hero__description">{{ $siteDescription }}</p>
        @endif
        <div class="ob-hero-actions">
            <a href="{{ route('register') }}" class="ob-btn ob-btn--primary">{{ __('openbook.home.cta_register') }}</a>
            <a href="{{ route('login') }}" class="ob-btn ob-btn--ghost">{{ __('openbook.home.cta_login') }}</a>
        </div>
    </section>

    @if (($staffMembers ?? collect())->isNotEmpty())
        <div class="ob-card ob-home-panel">
            <h2 class="ob-instance-staff__title">{{ __('openbook.home.staff_title') }}</h2>
            <ul class="ob-instance-staff__list">
                @foreach ($staffMembers as $staffMember)
                    @php
                        $staffName = $staffMember->profile?->display_name ?: $staffMember->username;
                        $staffRole = $staffMember->is_admin
                            ? __('openbook.home.staff_role_admin')
                            : __('openbook.home.staff_role_moderator');
                    @endphp
                    <li class="ob-instance-staff__item">
                        <a href="{{ route('profile.show', $staffMember->username) }}" class="ob-mini-profile__link">
                            <x-avatar :user="$staffMember" style="width:40px;height:40px" />
                            <div>
                                <div class="ob-post__author">{{ $staffName }}</div>
                                <div class="ob-post__handle">{{ '@'.$staffMember->username }}</div>
                            </div>
                        </a>
                        <span class="ob-instance-staff__role">{{ $staffRole }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="ob-card ob-home-panel ob-home-software">
        <h2>{{ __('openbook.home.software_title') }}</h2>
        <p>{{ __('openbook.home.hero_subtitle') }}</p>
        <p class="ob-home-software__meta">
            <a href="{{ config('openbook.homepage') }}" target="_blank" rel="noopener">Openbook</a>
            &middot; {{ config('openbook.release_label') }}
        </p>
    </div>
@endsection
