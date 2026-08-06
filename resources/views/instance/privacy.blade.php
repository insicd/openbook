@extends('layouts.app')

@section('title', __('openbook.privacy_policy.title').' - '.config('app.name'))

@section('content')
    <div class="ob-card">
        <h1>{{ __('openbook.privacy_policy.title') }}</h1>
        @if ($privacyHtml)
            <div class="ob-prose" style="margin-top:1rem">{!! $privacyHtml !!}</div>
        @else
            <p class="ob-field__help" style="margin-top:1rem">{{ __('openbook.privacy_policy.empty') }}</p>
        @endif
    </div>
@endsection
