<div class="ob-card ob-side-widget">
    <h2 class="ob-side-widget__title">{{ __('openbook.sidebar.instance_title') }}</h2>
    <p class="ob-side-widget__instance">
        <strong>{{ config('app.name') }}</strong>
        <span class="ob-post__handle">{{ config('openbook.domain') }}</span>
    </p>
    <p class="ob-field__help">{{ trans_choice('openbook.sidebar.members_count', $membersCount ?? 0, ['count' => $membersCount ?? 0]) }}</p>
</div>

@if (($suggestedActors ?? collect())->isNotEmpty())
    <div class="ob-card ob-side-widget">
        <h2 class="ob-side-widget__title">{{ __('openbook.sidebar.people_to_follow') }}</h2>
        @foreach ($suggestedActors as $actor)
            @php
                $suggestedUser = $actor->user;
                $suggestedName = $suggestedUser?->profile?->display_name ?: $suggestedUser?->username;
                $suggestedHandle = $suggestedUser ? '@'.$suggestedUser->username : '';
            @endphp
            @if ($suggestedUser)
                <div class="ob-suggestion">
                    <a href="{{ route('profile.show', $suggestedUser->username) }}" class="ob-mini-profile__link">
                        <x-avatar :user="$suggestedUser" style="width:40px;height:40px" />
                        <div>
                            <div class="ob-post__author">{{ $suggestedName }}</div>
                            <div class="ob-post__handle">{{ $suggestedHandle }}</div>
                        </div>
                    </a>
                    <form method="POST" action="{{ route('follow.store', $suggestedUser) }}">
                        @csrf
                        <button type="submit" class="ob-btn ob-btn--ghost ob-btn--small">{{ __('openbook.follow.follow') }}</button>
                    </form>
                </div>
            @endif
        @endforeach
    </div>
@endif
