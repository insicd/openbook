@extends('layouts.app')

@php
    $displayName = $profileUser->profile?->display_name ?: $profileUser->username;
    $handle = '@'.$profileUser->username.'@'.config('openbook.domain');
    $isOwnProfile = auth()->check() && auth()->id() === $profileUser->id;
@endphp

@section('title', $displayName.' ('.$handle.') - '.config('app.name'))

@section('content')
    <article class="ob-card" style="padding:0;overflow:hidden">
        <div class="ob-profile-cover"></div>
        <div class="ob-profile-header">
            <div class="ob-profile-avatar" aria-hidden="true">
                @if ($profileUser->profile?->avatarUrl())
                    <img src="{{ $profileUser->profile->avatarUrl() }}" alt="">
                @else
                    {{ mb_strtoupper(mb_substr($displayName, 0, 1)) }}
                @endif
            </div>

            <h1 class="ob-profile-name">{{ $displayName }}</h1>
            <p class="ob-profile-handle">{{ $handle }}</p>

            @if ($profileUser->actor?->manually_approves_followers)
                <span class="ob-badge">{{ __('openbook.profile.protected') }}</span>
            @endif

            @if ($profileUser->profile?->bio)
                <p>{{ $profileUser->profile->bio }}</p>
            @endif

            @if (! empty($profileUser->profile?->links))
                <ul aria-label="Collegamenti">
                    @foreach ($profileUser->profile->links as $link)
                        <li><a href="{{ $link['url'] }}" rel="me nofollow ugc">{{ $link['label'] ?? $link['url'] }}</a></li>
                    @endforeach
                </ul>
            @endif

            <div class="ob-profile-stats">
                <div><strong>{{ $followersCount }}</strong><span>{{ __('openbook.profile.followers') }}</span></div>
                <div><strong>{{ $followingCount }}</strong><span>{{ __('openbook.profile.following') }}</span></div>
                <div><strong>0</strong><span>{{ __('openbook.profile.communities') }}</span></div>
            </div>

            @unless ($isOwnProfile)
                <div style="margin-top:1rem">
                    @auth
                        @if ($isFollowing)
                            <form method="POST" action="{{ route('follow.destroy', $profileUser) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="ob-btn ob-btn--ghost">{{ __('openbook.follow.unfollow') }}</button>
                            </form>
                        @elseif ($hasPendingRequest)
                            <form method="POST" action="{{ route('follow.destroy', $profileUser) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="ob-btn ob-btn--ghost">{{ __('openbook.follow.cancel_request') }}</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('follow.store', $profileUser) }}">
                                @csrf
                                <button type="submit" class="ob-btn ob-btn--primary">{{ __('openbook.follow.follow') }}</button>
                            </form>
                        @endif
                    @else
                        <a href="{{ route('login') }}" class="ob-btn ob-btn--primary">{{ __('openbook.follow.follow') }}</a>
                    @endauth
                </div>
            @endunless

            <p class="ob-field__help" style="margin-top:1rem">
                {{ __('openbook.profile.joined_on', ['date' => $profileUser->created_at->translatedFormat('d F Y')]) }}
            </p>
        </div>
    </article>

    @if ($isOwnProfile && $pendingFollowRequests->isNotEmpty())
        <div class="ob-card">
            <h2>{{ __('openbook.follow.pending_requests') }}</h2>
            @foreach ($pendingFollowRequests as $request)
                @php
                    $requester = $request->follower->user;
                    $requesterName = $requester?->profile?->display_name ?: $requester?->username;
                @endphp
                <div class="ob-post__header" style="justify-content:space-between">
                    <div class="ob-post__header" style="margin-bottom:0">
                        <x-avatar :user="$requester" style="width:36px;height:36px" />
                        <a href="{{ route('profile.show', $requester->username) }}" class="ob-post__author">{{ $requesterName }}</a>
                    </div>
                    <div>
                        <form method="POST" action="{{ route('follow.accept', $request) }}" style="display:inline">
                            @csrf
                            <button type="submit" class="ob-btn ob-btn--primary">{{ __('openbook.follow.accept') }}</button>
                        </form>
                        <form method="POST" action="{{ route('follow.reject', $request) }}" style="display:inline">
                            @csrf
                            <button type="submit" class="ob-btn ob-btn--ghost">{{ __('openbook.follow.reject') }}</button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @forelse ($posts as $post)
        @include('posts._card', ['post' => $post])
    @empty
        <div class="ob-card">
            <h2>{{ __('openbook.profile.pinned_posts') }}</h2>
            <div class="ob-empty-state">
                <p>{{ __('openbook.profile.no_posts_yet') }}</p>
            </div>
        </div>
    @endforelse

    @if ($posts->hasPages())
        <div class="ob-pagination">
            {{ $posts->onEachSide(1)->links() }}
        </div>
    @endif
@endsection
