@extends('layouts.app')

@section('title', __('openbook.auth.login_title', ['app' => config('app.name')]))

@section('content')
    <div class="ob-card ob-narrow">
        <h1>{{ __('openbook.auth.login_title', ['app' => config('app.name')]) }}</h1>

        <form method="POST" action="{{ route('login') }}" novalidate>
            @csrf

            <div class="ob-field">
                <label for="login">{{ __('openbook.auth.login_identifier') }}</label>
                <input type="text" id="login" name="login" value="{{ old('login') }}"
                       required autofocus autocomplete="username"
                       @if ($errors->has('login')) aria-invalid="true" @endif>
                @error('login')
                    <p class="ob-field__error">{{ $message }}</p>
                @enderror
            </div>

            <div class="ob-field">
                <label for="password">{{ __('openbook.auth.password') }}</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>

            <div class="ob-field">
                <label class="ob-checkbox">
                    <input type="checkbox" name="remember" value="1">
                    {{ __('openbook.auth.remember_me') }}
                </label>
            </div>

            <button type="submit" class="ob-btn ob-btn--primary ob-btn--block">{{ __('openbook.auth.submit_login') }}</button>
        </form>

        <p style="margin-top:1rem">
            {{ __('openbook.auth.not_registered') }}
            <a href="{{ route('register') }}">{{ __('openbook.nav.register') }}</a>
        </p>
    </div>
@endsection
