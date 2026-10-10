@php
    $community = $actor->community;
    $status = $statusMap[$actor->id] ?? ['following' => false, 'pending' => false];
    $isOwner = $community !== null && $community->owner_user_id === auth()->id();
@endphp
<li class="ob-community-list__item">
    <a href="{{ $actor->profileUrl() }}" class="ob-mini-profile__link">
        <x-avatar :actor="$actor" style="width:48px;height:48px" />
        <div>
            <div class="ob-post__author">{!! $actor->displayNameHtml() !!}</div>
            <div class="ob-post__handle">!{{ $actor->handle() }}</div>
            @if (filled($actor->summary))
                <p class="ob-field__help">{{ \Illuminate\Support\Str::limit(strip_tags($actor->summary), 120) }}</p>
            @endif
        </div>
    </a>
    <div class="ob-community-list__meta">
        @if ($community?->is_private)
            <span class="ob-badge">{{ __('openbook.communities.private_badge') }}</span>
        @endif
        @if ($community !== null)
            <span class="ob-field__help">{{ trans_choice('openbook.communities.members_count', $community->members_count, ['count' => $community->members_count]) }}</span>
        @endif
        @auth
            @if ($isOwner)
                <span class="ob-field__help">{{ __('openbook.communities.list_owned') }}</span>
            @elseif ($status['following'])
                <form method="POST" action="{{ $community !== null ? route('communities.leave', $community) : route('actors.unfollow', $actor) }}" data-community-membership-form>
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="ob-btn ob-btn--ghost ob-btn--small">{{ __('openbook.communities.list_leave') }}</button>
                </form>
            @elseif ($status['pending'])
                <form method="POST" action="{{ $community !== null ? route('communities.leave', $community) : route('actors.unfollow', $actor) }}" data-community-membership-form>
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="ob-btn ob-btn--ghost ob-btn--small">{{ __('openbook.communities.list_cancel_request') }}</button>
                </form>
            @elseif (! $actor->isRemotelySuspended())
                <form method="POST" action="{{ $community !== null ? route('communities.join', $community) : route('actors.follow', $actor) }}" data-community-membership-form>
                    @csrf
                    <button type="submit" class="ob-btn ob-btn--primary ob-btn--small">{{ $community?->is_private ? __('openbook.communities.request_join') : __('openbook.communities.join') }}</button>
                </form>
            @endif
        @else
            <a href="{{ route('login') }}" class="ob-btn ob-btn--primary ob-btn--small">{{ __('openbook.communities.join') }}</a>
        @endauth
    </div>
</li>
