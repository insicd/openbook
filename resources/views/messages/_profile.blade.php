@php
    /** @var \App\Federation\Actors\Actor $quotedActor */
    $showFollow = $showFollow ?? false;
    $viewerActor = auth()->user()?->actor;
    $isSelf = $viewerActor !== null && $viewerActor->id === $quotedActor->id;
    $alreadyFollowing = $viewerActor !== null && ! $isSelf
        && app(\App\Application\Services\FollowManager::class)->isFollowing($viewerActor, $quotedActor);
    $followAction = ($quotedActor->isLocal() && $quotedActor->user)
        ? route('follow.store', $quotedActor->user)
        : route('actors.follow', $quotedActor);
@endphp
<div class="ob-message-profile">
    <a href="{{ $quotedActor->profileUrl() }}" class="ob-mini-profile__link">
        <x-avatar :actor="$quotedActor" style="width:40px;height:40px" />
        <div>
            <div class="ob-post__author">{{ $quotedActor->displayName() }}</div>
            <div class="ob-post__handle">{{ '@'.$quotedActor->handle() }}</div>
        </div>
    </a>
    @if ($showFollow && $viewerActor && ! $isSelf && ! $alreadyFollowing)
        <form method="POST" action="{{ $followAction }}">
            @csrf
            <button type="submit" class="ob-btn ob-btn--primary ob-btn--small">{{ __('openbook.follow.follow') }}</button>
        </form>
    @endif
</div>
