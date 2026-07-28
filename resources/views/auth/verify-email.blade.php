@extends('layouts.app')

@section('title', __('openbook.verify_email.title').' - '.config('app.name'))

@section('content')
    <div class="ob-card ob-narrow">
        <h1>{{ __('openbook.verify_email.title') }}</h1>
        <p>{{ __('openbook.verify_email.body') }}</p>

        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" class="ob-btn ob-btn--primary">{{ __('openbook.verify_email.resend') }}</button>
        </form>
    </div>
@endsection
