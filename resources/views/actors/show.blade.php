@extends('layouts.app')

@php
    $displayName = $profileActor->displayName();
    $handle = '@'.$profileActor->handle();
    $isGroup = $profileActor->isGroup();
    $isFeed = $profileActor->isFeed();
    $feedSource = $isFeed ? $profileActor->feedSource : null;
@endphp

@section('title', $displayName.' ('.$handle.') - '.config('app.name'))

@section('content')
    <div class="ob-alert ob-alert--info" role="note">
        @if ($isFeed)
            {{ __('openbook.actors.feed_notice') }}
        @elseif ($isGroup)
            {{ __('openbook.actors.remote_group_notice') }}
        @else
            {{ __('openbook.actors.remote_notice') }}
        @endif
    </div>

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
            <p class="ob-profile-handle">{{ $isGroup ? '!'.$profileActor->handle() : $handle }}</p>

            @if ($isFeed)
                <span class="ob-badge">{{ __('openbook.actors.feed_badge') }}</span>
            @elseif ($isGroup)
                <span class="ob-badge">{{ __('openbook.communities.remote_badge') }}</span>
            @endif

            @if ($profileActor->manually_approves_followers)
                <span class="ob-badge">{{ __('openbook.profile.protected') }}</span>
            @endif

            @if ($profileActor->summary)
                <div class="ob-profile-bio">{{ \App\Domain\Posts\PostBodyRenderer::render(\App\Federation\Inbox\RemoteContentSanitizer::toPlainText($profileActor->summary)) }}</div>
            @endif

            @if ($feedSource)
                <p class="ob-field__help">
                    @if ($feedSource->site_url)
                        <a href="{{ $feedSource->site_url }}" rel="noopener noreferrer" target="_blank">{{ __('openbook.actors.feed_website') }}</a>
                        ·
                    @endif
                    <a href="{{ $feedSource->feed_url }}" rel="noopener noreferrer" target="_blank">{{ __('openbook.actors.feed_source') }}</a>
                </p>
            @endif

            <div class="ob-profile-toolbar">
                <div class="ob-profile-stats">
                    <a href="{{ route('actors.followers', $profileActor) }}"><strong>{{ \App\Support\CompactNumber::format((int) $followersCount) }}</strong><span>{{ $isGroup ? __('openbook.communities.members') : __('openbook.profile.followers') }}</span></a>
                    @unless ($isGroup || $isFeed)
                        <a href="{{ route('actors.following', $profileActor) }}"><strong>{{ \App\Support\CompactNumber::format((int) $followingCount) }}</strong><span>{{ __('openbook.profile.following') }}</span></a>
                    @endunless
                </div>

                <div class="ob-profile-toolbar__actions">
                    @auth
                        @if ($isFollowing)
                            <form method="POST" action="{{ route('actors.unfollow', $profileActor) }}" class="ob-profile-toolbar__form">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="ob-btn ob-btn--ghost ob-btn--small">{{ $isGroup ? __('openbook.communities.leave') : __('openbook.follow.unfollow') }}</button>
                            </form>
                        @elseif ($hasPendingRequest)
                            <form method="POST" action="{{ route('actors.unfollow', $profileActor) }}" class="ob-profile-toolbar__form">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="ob-btn ob-btn--ghost ob-btn--small">{{ $isGroup ? __('openbook.communities.pending') : __('openbook.follow.cancel_request') }}</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('actors.follow', $profileActor) }}" class="ob-profile-toolbar__form">
                                @csrf
                                <button type="submit" class="ob-btn ob-btn--primary ob-btn--small">{{ $isGroup ? __('openbook.communities.join') : __('openbook.follow.follow') }}</button>
                            </form>
                        @endif
                        @if (! $isGroup && ! $isFeed)
                            <a href="{{ route('messages.open_actor', $profileActor) }}" class="ob-icon-btn ob-profile-toolbar__message"
                                aria-label="{{ __('openbook.messages.message_aria') }}"
                                title="{{ __('openbook.messages.message_aria') }}">
                                <x-icon name="message" />
                            </a>
                            @include('profile._share_to_user', ['actor' => $profileActor])
                        @endif
                    @else
                        <a href="{{ route('login') }}" class="ob-btn ob-btn--primary ob-btn--small">{{ $isGroup ? __('openbook.communities.join') : __('openbook.follow.follow') }}</a>
                    @endauth
                </div>
            </div>

            @if ($profileActor->published_at)
                <p class="ob-field__help" style="margin-top:1rem">
                    {{ __('openbook.profile.joined_on', ['date' => $profileActor->published_at->translatedFormat('d F Y')]) }}
                </p>
            @endif

            @include('profile._tabs', [
                'activeTab' => $activeTab ?? 'posts',
                'showActivityTab' => ! $isGroup && ! $isFeed,
                'postsUrl' => route('actors.show', $profileActor),
                'activityUrl' => route('actors.activity', $profileActor),
                'photosUrl' => route('actors.photos', $profileActor),
            ])
        </div>
    </article>

    @auth
        @if ($isGroup && $isFollowing && ($activeTab ?? 'posts') === 'posts')
            @include('posts._composer', [
                'addressedGroupActor' => $profileActor,
            ])
        @endif
    @endauth

    @if (($activeTab ?? 'posts') === 'photos')
        @include('profile._photo_grid', ['media' => $media])
    @elseif (($activeTab ?? 'posts') === 'activity')
        @include('profile._activity', [
            'activity' => $activity ?? null,
            'activityNotice' => __('openbook.profile.activity_remote_notice'),
        ])
    @else
        @include('posts._feed', [
            'posts' => $posts,
            'emptyMessage' => $emptyPostsMessage ?? ($isGroup ? __('openbook.communities.wall_empty') : __('openbook.profile.no_posts_yet')),
        ])
    @endif
@endsection
