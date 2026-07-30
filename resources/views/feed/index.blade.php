@extends('layouts.app')

@section('title', __('openbook.nav.home').' - '.config('app.name'))

@section('content')
    @unless ($currentUser->hasVerifiedEmail())
        <div class="ob-alert" style="background:#fff6e0;border-color:#f0dfa6;color:#8a6d1c">
            {{ __('openbook.verify_email.body') }}
            <a href="{{ route('verification.notice') }}">{{ __('openbook.verify_email.title') }}</a>
        </div>
    @endunless

    @include('posts._composer', ['quotedPost' => $quotedPost ?? null])

    @include('posts._feed', ['posts' => $posts, 'emptyMessage' => __('openbook.feed.empty')])
@endsection
