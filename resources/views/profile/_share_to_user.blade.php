@if ($actor && $actor->isPerson() && $actor->isActive())
    <a href="{{ $actor->isLocal() && $actor->user
            ? route('profiles.share_to_user', $actor->user)
            : route('actors.share_to_user', $actor) }}"
        class="ob-icon-btn ob-profile-toolbar__share"
        aria-label="{{ __('openbook.messages.share_profile_aria') }}"
        title="{{ __('openbook.messages.share_profile_aria') }}">
        <x-icon name="share" />
    </a>
@endif
