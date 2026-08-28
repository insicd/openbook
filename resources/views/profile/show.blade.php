@extends('layouts.app')

@php
    $displayName = $profileUser->profile?->display_name ?: $profileUser->username;
    $handle = '@'.$profileUser->username.'@'.config('openbook.domain');
    $isOwnProfile = auth()->check() && auth()->id() === $profileUser->id;
@endphp

@section('title', $displayName.' ('.$handle.') - '.config('app.name'))

@section('content')
    <article class="ob-card" style="padding:0;overflow:hidden">
        <div class="ob-profile-cover" @if ($profileUser->profile?->coverUrl()) style="background-image:url('{{ $profileUser->profile->coverUrl() }}');background-size:cover;background-position:center" @endif></div>
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

            @if ($profileSuspended ?? false)
                <div class="ob-alert ob-alert--error" style="margin-top:1rem" role="status">
                    {{ __('openbook.profile.suspended_notice') }}
                </div>
            @else
            @if ($profileUser->actor?->manually_approves_followers)
                <span class="ob-badge">{{ __('openbook.profile.protected') }}</span>
            @endif

            @if ($profileUser->profile?->bio)
                <div class="ob-profile-bio">{{ \App\Domain\Posts\PostBodyRenderer::render($profileUser->profile->bio) }}</div>
            @endif

            @if (! empty($profileUser->profile?->links))
                <ul aria-label="Collegamenti">
                    @foreach ($profileUser->profile->links as $link)
                        <li><a href="{{ $link['url'] }}" rel="me nofollow ugc">{{ $link['label'] ?? $link['url'] }}</a></li>
                    @endforeach
                </ul>
            @endif

            <div class="ob-profile-toolbar">
                <div class="ob-profile-stats">
                    <a href="{{ route('profile.followers', $profileUser->username) }}"><strong>{{ $followersCount }}</strong><span>{{ __('openbook.profile.followers') }}</span></a>
                    <a href="{{ route('profile.following', $profileUser->username) }}"><strong>{{ $followingCount }}</strong><span>{{ __('openbook.profile.following') }}</span></a>
                    <div><strong>{{ $communitiesCount ?? 0 }}</strong><span>{{ __('openbook.profile.communities') }}</span></div>
                </div>

                @unless ($isOwnProfile)
                    <div class="ob-profile-toolbar__actions">
                        @auth
                            @if ($isFollowing)
                                <form method="POST" action="{{ route('follow.destroy', $profileUser) }}" class="ob-profile-toolbar__form">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="ob-btn ob-btn--ghost ob-btn--small">{{ __('openbook.follow.unfollow') }}</button>
                                </form>
                            @elseif ($hasPendingRequest)
                                <form method="POST" action="{{ route('follow.destroy', $profileUser) }}" class="ob-profile-toolbar__form">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="ob-btn ob-btn--ghost ob-btn--small">{{ __('openbook.follow.cancel_request') }}</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('follow.store', $profileUser) }}" class="ob-profile-toolbar__form">
                                    @csrf
                                    <button type="submit" class="ob-btn ob-btn--primary ob-btn--small">{{ __('openbook.follow.follow') }}</button>
                                </form>
                            @endif
                            <a href="{{ route('messages.open', $profileUser->username) }}" class="ob-icon-btn ob-profile-toolbar__message"
                                aria-label="{{ __('openbook.messages.message_aria') }}"
                                title="{{ __('openbook.messages.message_aria') }}">
                                <x-icon name="message" />
                            </a>
                            @include('profile._share_to_user', ['actor' => $profileUser->actor])
                        @else
                            <a href="{{ route('login') }}" class="ob-btn ob-btn--primary ob-btn--small">{{ __('openbook.follow.follow') }}</a>
                        @endauth
                    </div>
                @else
                    @auth
                        <div class="ob-profile-toolbar__actions">
                            @include('profile._share_to_user', ['actor' => $profileUser->actor])
                        </div>
                    @endauth
                @endunless
            </div>

            @if ($isOwnProfile)
                <div style="margin-top:1rem">
                    <a href="{{ route('settings.edit') }}" class="ob-btn ob-btn--ghost">{{ __('openbook.settings.edit_profile') }}</a>
                </div>
            @endif

            <p class="ob-field__help" style="margin-top:1rem">
                {{ __('openbook.profile.joined_on', ['date' => $profileUser->created_at->translatedFormat('d F Y')]) }}
            </p>

            @include('profile._tabs', [
                'activeTab' => $activeTab ?? 'posts',
                'postsUrl' => route('profile.show', $profileUser->username),
                'activityUrl' => route('profile.activity', $profileUser->username),
                'photosUrl' => route('profile.photos', $profileUser->username),
            ])
            @endif
        </div>
    </article>

    @if (! ($profileSuspended ?? false))
    @if ($isOwnProfile && ($activeTab ?? 'posts') === 'posts' && $pendingFollowRequests->isNotEmpty())
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

    @if (($activeTab ?? 'posts') === 'photos')
        @include('profile._photo_grid', ['media' => $media])
    @elseif (($activeTab ?? 'posts') === 'activity')
        @include('profile._activity', ['activity' => $activity ?? null])
    @else
        @include('posts._feed', ['posts' => $posts, 'emptyMessage' => __('openbook.profile.no_posts_yet')])
    @endif
    @endif
@endsection
