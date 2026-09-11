<div class="ob-suggestion">
    <a href="{{ route('hashtags.show', $hashtag->name) }}" class="ob-mini-profile__link">
        <span class="ob-followed-hashtag__icon"><x-icon name="hash" /></span>
        <div>
            <div class="ob-post__author">#{{ $hashtag->name }}</div>
            <div class="ob-post__handle">{{ __('openbook.follows.hashtag') }}</div>
        </div>
    </a>

    @include('hashtags._follow-button', [
        'hashtagName' => $hashtag->name,
        'isFollowing' => true,
    ])
</div>
