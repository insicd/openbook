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

        @if (($staffMembers ?? collect())->isNotEmpty())
            <div class="ob-instance-staff">
                <h3 class="ob-instance-staff__title">{{ __('openbook.home.staff_title') }}</h3>
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
    </div>
@endsection
