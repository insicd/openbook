@extends('layouts.app')

@section('title', __('openbook.instance_rules.title').' - '.config('app.name'))

@section('content')
    <div class="ob-card ob-narrow">
        <h1>{{ __('openbook.instance_rules.title') }}</h1>
        @if ($rulesHtml)
            <div class="ob-prose" style="margin-top:1rem">{!! $rulesHtml !!}</div>
        @else
            <p class="ob-field__help" style="margin-top:1rem">{{ __('openbook.instance_rules.empty') }}</p>
        @endif
    </div>
@endsection
