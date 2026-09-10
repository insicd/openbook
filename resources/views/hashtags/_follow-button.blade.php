@auth
    <form
        method="POST"
        action="{{ ($isFollowing ?? false)
            ? route('hashtags.unfollow', ['name' => $hashtagName])
            : route('hashtags.follow', ['name' => $hashtagName]) }}"
        class="ob-hashtag-follow-form"
    >
        @csrf
        @if ($isFollowing ?? false)
            @method('DELETE')
        @endif
        <button type="submit" class="ob-btn {{ ($isFollowing ?? false) ? 'ob-btn--ghost' : 'ob-btn--primary' }} ob-btn--small">
            {{ ($isFollowing ?? false) ? __('openbook.follow.unfollow') : __('openbook.follow.follow') }}
        </button>
    </form>
@endauth
