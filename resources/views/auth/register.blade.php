@extends('layouts.app')

@section('title', __('openbook.auth.register_title').' - '.config('app.name'))

@section('content')
    <div class="ob-card ob-narrow">
        <h1>{{ __('openbook.auth.register_title') }}</h1>

        <form method="POST" action="{{ route('register') }}" novalidate>
            @csrf

            <div class="ob-field">
                <label for="username">{{ __('openbook.auth.username') }}</label>
                <input type="text" id="username" name="username" value="{{ old('username') }}"
                       required minlength="3" maxlength="32" pattern="[a-z0-9_]+"
                       autocomplete="username" aria-describedby="username-help"
                       @if ($errors->has('username')) aria-invalid="true" @endif>
                <p id="username-help" class="ob-field__help">
                    {{ __('openbook.auth.username_help', ['handle' => '@'.(old('username') ?: 'nomeutente').'@'.config('openbook.domain')]) }}
                </p>
                @error('username')
                    <p class="ob-field__error">{{ $message }}</p>
                @enderror
            </div>

            <div class="ob-field">
                <label for="email">{{ __('openbook.auth.email') }}</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}"
                       required autocomplete="email"
                       @if ($errors->has('email')) aria-invalid="true" @endif>
                @error('email')
                    <p class="ob-field__error">{{ $message }}</p>
                @enderror
            </div>

            <div class="ob-field">
                <label for="password">{{ __('openbook.auth.password') }}</label>
                <input type="password" id="password" name="password" required minlength="8"
                       autocomplete="new-password"
                       @if ($errors->has('password')) aria-invalid="true" @endif>
                @error('password')
                    <p class="ob-field__error">{{ $message }}</p>
                @enderror
            </div>

            <div class="ob-field">
                <label for="password_confirmation">{{ __('openbook.auth.password_confirmation') }}</label>
                <input type="password" id="password_confirmation" name="password_confirmation" required minlength="8"
                       autocomplete="new-password">
            </div>

            <button type="submit" class="ob-btn ob-btn--primary ob-btn--block">{{ __('openbook.auth.submit_register') }}</button>
        </form>

        <p style="margin-top:1rem">
            {{ __('openbook.auth.already_registered') }}
            <a href="{{ route('login') }}">{{ __('openbook.nav.login') }}</a>
        </p>
    </div>
@endsection
