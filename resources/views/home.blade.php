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
    @if ($guestHomeFeed ?? false)
        <div class="ob-guest-home-layout">
            <aside class="ob-guest-home-aside" id="ob-guest-home-aside">
                @include('home._intro')
            </aside>
            <section class="ob-guest-home-timeline" aria-labelledby="ob-guest-home-feed-title">
                <h2 id="ob-guest-home-feed-title" class="ob-guest-home-timeline__title">
                    {{ __('openbook.home.feed_title', ['name' => $siteName ?? config('app.name')]) }}
                </h2>
                @include('posts._feed', [
                    'posts' => $posts,
                    'emptyMessage' => __('openbook.home.feed_empty'),
                    'asyncFeed' => true,
                ])
            </section>
        </div>
    @else
        @include('home._intro')
    @endif
@endsection
