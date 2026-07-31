@extends('layouts.app')

@section('title', $community->actor->displayName().' - '.config('app.name'))

@section('content')
    <div class="ob-card ob-profile">
        <div class="ob-profile__header">
            <x-avatar :actor="$community->actor" style="width:88px;height:88px;font-size:2rem" />
            <div>
                <h1>{{ $community->actor->displayName() }}</h1>
                <p class="ob-post__handle">{{ '!'.$community->slug.'@'.config('openbook.domain') }}</p>
                @if (filled($community->actor->summary))
                    <div class="ob-profile__bio">{{ \App\Domain\Posts\PostBodyRenderer::render($community->actor->summary) }}</div>
                @endif
                <div class="ob-profile-stats">
                    <div><strong>{{ $community->members_count }}</strong><span>{{ __('openbook.communities.members') }}</span></div>
                    <div><strong>{{ $community->posts_count }}</strong><span>{{ __('openbook.communities.posts') }}</span></div>
                </div>
                <p class="ob-field__help">
                    {{ __('openbook.communities.owned_by') }}
                    <a href="{{ route('profile.show', $community->owner->username) }}">{{ $community->owner->profile?->display_name ?: $community->owner->username }}</a>
                    @if ($community->is_private)
                        &middot; {{ __('openbook.communities.private_badge') }}
                    @endif
                </p>

                @auth
                    <div style="margin-top:1rem;display:flex;gap:0.5rem;flex-wrap:wrap">
                        @if ($community->isOwnedBy(auth()->user()))
                            <span class="ob-field__help">{{ __('openbook.communities.you_are_owner') }}</span>
                        @elseif ($isMember)
                            <form method="POST" action="{{ route('communities.leave', $community) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="ob-btn ob-btn--ghost">{{ __('openbook.communities.leave') }}</button>
                            </form>
                        @elseif ($hasPendingRequest)
                            <span class="ob-field__help">{{ __('openbook.communities.pending') }}</span>
                        @else
                            <form method="POST" action="{{ route('communities.join', $community) }}">
                                @csrf
                                <button type="submit" class="ob-btn ob-btn--primary">{{ __('openbook.communities.join') }}</button>
                            </form>
                        @endif
                    </div>
                @endauth
            </div>
        </div>
    </div>

    @if ($canManageModerators ?? false)
        <div class="ob-card">
            <h2>{{ __('openbook.communities.moderators_title') }}</h2>
            <p class="ob-field__help">{{ __('openbook.communities.moderators_help') }}</p>

            @if ($community->moderators->isNotEmpty())
                <ul class="ob-community-list">
                    @foreach ($community->moderators as $moderator)
                        <li class="ob-community-list__item">
                            <a href="{{ route('profile.show', $moderator->username) }}" class="ob-mini-profile__link">
                                <x-avatar :user="$moderator" style="width:40px;height:40px" />
                                <div>
                                    <div class="ob-post__author">{{ $moderator->profile?->display_name ?: $moderator->username }}</div>
                                    <div class="ob-post__handle">{{ '@'.$moderator->username }}</div>
                                </div>
                            </a>
                            <form method="POST" action="{{ route('communities.moderators.destroy', [$community, $moderator]) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="ob-btn ob-btn--ghost ob-btn--small">{{ __('openbook.communities.remove_moderator') }}</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif

            <form method="POST" action="{{ route('communities.moderators.store', $community) }}" style="margin-top:1rem">
                @csrf
                <div class="ob-field">
                    <label for="moderator-username">{{ __('openbook.communities.add_moderator') }}</label>
                    <input type="text" id="moderator-username" name="username" required maxlength="32" placeholder="username">
                    @error('username') <p class="ob-field__error">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="ob-btn ob-btn--primary ob-btn--small">{{ __('openbook.communities.add_moderator_submit') }}</button>
            </form>
        </div>
    @endif

    @if ($pendingJoinRequests->isNotEmpty())
        <div class="ob-card">
            <h2>{{ __('openbook.communities.pending_requests') }}</h2>
            @foreach ($pendingJoinRequests as $request)
                <div class="ob-suggestion">
                    <a href="{{ $request->follower->profileUrl() }}" class="ob-mini-profile__link">
                        <x-avatar :actor="$request->follower" style="width:40px;height:40px" />
                        <div>
                            <div class="ob-post__author">{{ $request->follower->displayName() }}</div>
                            <div class="ob-post__handle">{{ '@'.$request->follower->preferred_username }}</div>
                        </div>
                    </a>
                    <div style="display:flex;gap:0.35rem">
                        <form method="POST" action="{{ route('communities.accept', [$community, $request]) }}">
                            @csrf
                            <button type="submit" class="ob-btn ob-btn--primary ob-btn--small">{{ __('openbook.follow.accept') }}</button>
                        </form>
                        <form method="POST" action="{{ route('communities.reject', [$community, $request]) }}">
                            @csrf
                            <button type="submit" class="ob-btn ob-btn--ghost ob-btn--small">{{ __('openbook.follow.reject') }}</button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($canPost ?? false)
        @include('posts._composer', [
            'composerCommunities' => collect([$community]),
            'selectedCommunityId' => $community->id,
        ])
    @endif

    @include('posts._feed', [
        'posts' => $posts,
        'emptyMessage' => __('openbook.communities.wall_empty'),
    ])
@endsection
