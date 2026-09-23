@php
    $autoAnnounce = $autoAnnounce ?? false;
@endphp

<details class="ob-post__share-menu ob-profile-toolbar__menu">
    <summary
        class="ob-icon-btn{{ $autoAnnounce ? ' ob-icon-btn--active' : '' }}"
        aria-label="{{ __('openbook.follow.auto_announce_menu') }}"
        title="{{ __('openbook.follow.auto_announce_menu') }}"
    >
        <x-icon name="share" />
    </summary>
    <div class="ob-post__menu-panel" role="menu">
        @if ($autoAnnounce)
            <form method="POST" action="{{ route('actors.auto_announce.destroy', $actor) }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="ob-post__menu-item" role="menuitem">
                    <x-icon name="check" />
                    {{ __('openbook.follow.auto_announce_disable') }}
                </button>
            </form>
        @else
            <form method="POST" action="{{ route('actors.auto_announce', $actor) }}">
                @csrf
                <button type="submit" class="ob-post__menu-item" role="menuitem">
                    <x-icon name="share" />
                    {{ __('openbook.follow.auto_announce_enable') }}
                </button>
            </form>
        @endif
        <p class="ob-field__help ob-profile-toolbar__menu-help">{{ __('openbook.follow.auto_announce_help') }}</p>
    </div>
</details>
