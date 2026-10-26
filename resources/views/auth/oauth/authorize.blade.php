@extends('layouts.app')

@section('title', __('oauth.title'))

@section('content')
    <div class="ob-card ob-narrow">
        <h1>{{ __('oauth.title') }}</h1>
        <p>{{ __('oauth.description', ['app' => $client->name, 'account' => $user->federatedHandle()]) }}</p>
        <p>{{ __('oauth.permissions') }}</p>
        <form method="POST" action="{{ route('passport.authorizations.approve') }}">
            @csrf
            <input type="hidden" name="state" value="{{ $request->state }}">
            <input type="hidden" name="client_id" value="{{ $client->id }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="ob-btn ob-btn--primary ob-btn--block">{{ __('oauth.allow') }}</button>
        </form>
        <form method="POST" action="{{ route('passport.authorizations.deny') }}" style="margin-top:1rem">
            @csrf
            @method('DELETE')
            <input type="hidden" name="state" value="{{ $request->state }}">
            <input type="hidden" name="client_id" value="{{ $client->id }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="ob-btn ob-btn--block">{{ __('oauth.deny') }}</button>
        </form>
    </div>
@endsection
