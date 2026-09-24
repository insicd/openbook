@php
    $actor = $actor ?? null;
    $autoAnnounce = $autoAnnounce ?? false;
    $showAutoAnnounce = (bool) ($showAutoAnnounce ?? false);
    $showShareToUser = $actor !== null && $actor->isPerson() && $actor->isActive();
    $shareUrl = null;

    if ($showShareToUser) {
        $shareUrl = $actor->isLocal() && $actor->user
            ? route('profiles.share_to_user', $actor->user)
            : route('actors.share_to_user', $actor);
    }
@endphp

@if ($actor && ($showAutoAnnounce || $showShareToUser))
    <details class="ob-post__menu ob-profile-toolbar__menu">
        <summary
            class="ob-icon-btn{{ $autoAnnounce ? ' ob-icon-btn--active' : '' }}"
            aria-label="{{ __('openbook.profile.actions_menu') }}"
            title="{{ __('openbook.profile.actions_menu') }}"
        >
            <x-icon name="more-vertical" />
        </summary>
        <div class="ob-post__menu-panel" role="menu">
            @if ($showAutoAnnounce)
                @if ($autoAnnounce)
                    <form method="POST" action="{{ route('actors.auto_announce.destroy', $actor) }}">
                        @csrf
                        @method('DELETE')
                        <button
                            type="submit"
                            class="ob-post__menu-item"
                            role="menuitem"
                            data-confirm-title="{{ __('openbook.follow.auto_announce_disable') }}"
                            data-confirm-body="{{ __('openbook.follow.auto_announce_disable_help') }}"
                            data-confirm-submit="{{ __('openbook.follow.auto_announce_disable_confirm') }}"
                        >
                            <x-icon name="check" />
                            {{ __('openbook.follow.auto_announce_disable') }}
                        </button>
                    </form>
                @else
                    <form method="POST" action="{{ route('actors.auto_announce', $actor) }}">
                        @csrf
                        <button
                            type="submit"
                            class="ob-post__menu-item"
                            role="menuitem"
                            data-confirm-title="{{ __('openbook.follow.auto_announce_enable') }}"
                            data-confirm-body="{{ __('openbook.follow.auto_announce_help') }}"
                            data-confirm-submit="{{ __('openbook.follow.auto_announce_enable_confirm') }}"
                        >
                            <x-icon name="share" />
                            {{ __('openbook.follow.auto_announce_enable') }}
                        </button>
                    </form>
                @endif
            @endif
            @if ($showShareToUser)
                <a
                    href="{{ $shareUrl }}"
                    class="ob-post__menu-item"
                    role="menuitem"
                    aria-label="{{ __('openbook.messages.share_profile_aria') }}"
                    data-confirm-title="{{ __('openbook.messages.share_title') }}"
                    data-confirm-body="{{ __('openbook.messages.share_profile_intro') }}"
                    data-confirm-submit="{{ __('openbook.profile.actions_continue') }}"
                >
                    <x-icon name="message" />
                    {{ __('openbook.actions.announce_share_user') }}
                </a>
            @endif
        </div>
    </details>
@endif
