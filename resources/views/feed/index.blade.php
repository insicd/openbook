@extends('layouts.app')

@section('title', __('openbook.nav.home').' - '.config('app.name'))

@section('content')
    @unless ($currentUser->hasVerifiedEmail())
        <div class="ob-alert" style="background:#fff6e0;border-color:#f0dfa6;color:#8a6d1c">
            {{ __('openbook.verify_email.body') }}
            <a href="{{ route('verification.notice') }}">{{ __('openbook.verify_email.title') }}</a>
        </div>
    @endunless

    @include('posts._composer')

    @forelse ($posts as $post)
        @include('posts._card', ['post' => $post])
    @empty
        <div class="ob-card">
            <div class="ob-empty-state">
                <p>{{ __('openbook.feed.empty') }}</p>
            </div>
        </div>
    @endforelse

    @if ($posts->hasPages())
        <div class="ob-pagination">
            {{ $posts->onEachSide(1)->links() }}
        </div>
    @endif
@endsection
