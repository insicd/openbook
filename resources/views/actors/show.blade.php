@extends('layouts.app')

@php
    $displayName = $profileActor->displayName();
    $handle = '@'.$profileActor->handle();
@endphp

@section('title', $displayName.' ('.$handle.') - '.config('app.name'))

@section('content')
    <div class="ob-alert ob-alert--info" role="note">{{ __('openbook.actors.remote_notice') }}</div>

    <article class="ob-card" style="padding:0;overflow:hidden">
            <div class="ob-profile-cover" @if ($profileActor->coverUrl()) style="background-image:url('{{ $profileActor->coverUrl() }}');background-size:cover;background-position:center" @endif></div>
        <div class="ob-profile-header">
            <div class="ob-profile-avatar" aria-hidden="true">
                @if ($profileActor->avatarUrl())
                    <img src="{{ $profileActor->avatarUrl() }}" alt="">
                @else
                    {{ mb_strtoupper(mb_substr($displayName, 0, 1)) }}
                @endif
            </div>

            <h1 class="ob-profile-name">{{ $displayName }}</h1>
            <p class="ob-profile-handle">{{ $handle }}</p>

            @if ($profileActor->manually_approves_followers)
                <span class="ob-badge">{{ __('openbook.profile.protected') }}</span>
            @endif

            @if ($profileActor->summary)
                <div class="ob-profile-bio">{{ \App\Domain\Posts\PostBodyRenderer::render(\App\Federation\Inbox\RemoteContentSanitizer::toPlainText($profileActor->summary)) }}</div>
            @endif

                    <div class="ob-profile-stats">
                        <a href="{{ route('actors.followers', $profileActor) }}"><strong>{{ $followersCount }}</strong><span>{{ __('openbook.profile.followers') }}</span></a>
                        <a href="{{ route('actors.following', $profileActor) }}"><strong>{{ $followingCount }}</strong><span>{{ __('openbook.profile.following') }}</span></a>
                    </div>

            <div style="margin-top:1rem">
                @auth
                    @if ($isFollowing)
                        <form method="POST" action="{{ route('actors.unfollow', $profileActor) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="ob-btn ob-btn--ghost">{{ __('openbook.follow.unfollow') }}</button>
                        </form>
                    @elseif ($hasPendingRequest)
                        <form method="POST" action="{{ route('actors.unfollow', $profileActor) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="ob-btn ob-btn--ghost">{{ __('openbook.follow.cancel_request') }}</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('actors.follow', $profileActor) }}">
                            @csrf
                            <button type="submit" class="ob-btn ob-btn--primary">{{ __('openbook.follow.follow') }}</button>
                        </form>
                    @endif
                @else
                    <a href="{{ route('login') }}" class="ob-btn ob-btn--primary">{{ __('openbook.follow.follow') }}</a>
                @endauth
            </div>
        </div>
    </article>

    @include('posts._feed', ['posts' => $posts, 'emptyMessage' => __('openbook.profile.no_posts_yet')])
@endsection
