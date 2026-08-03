@php
    /** @var array{staff: \Illuminate\Support\Collection, local: \Illuminate\Support\Collection, remote: \Illuminate\Support\Collection} $welcomeKit */
    $hasSuggestions = $welcomeKit['staff']->isNotEmpty()
        || $welcomeKit['local']->isNotEmpty()
        || $welcomeKit['remote']->isNotEmpty();
@endphp

<div class="ob-card ob-welcome">
    <div class="ob-welcome__intro">
        <h2 class="ob-welcome__title">{{ __('openbook.feed.welcome_title', ['app' => config('app.name')]) }}</h2>
        <p class="ob-welcome__body">{{ __('openbook.feed.welcome_body') }}</p>
    </div>

    @if ($welcomeKit['staff']->isNotEmpty())
        <section class="ob-welcome__section" aria-labelledby="ob-welcome-staff">
            <h3 id="ob-welcome-staff" class="ob-welcome__section-title">{{ __('openbook.feed.welcome_staff') }}</h3>
            @foreach ($welcomeKit['staff'] as $staffMember)
                @php
                    $staffName = $staffMember->profile?->display_name ?: $staffMember->username;
                    $staffRole = $staffMember->is_admin
                        ? __('openbook.home.staff_role_admin')
                        : __('openbook.home.staff_role_moderator');
                @endphp
                <div class="ob-suggestion">
                    <a href="{{ route('profile.show', $staffMember->username) }}" class="ob-mini-profile__link">
                        <x-avatar :user="$staffMember" style="width:40px;height:40px" />
                        <div>
                            <div class="ob-post__author">{{ $staffName }}</div>
                            <div class="ob-post__handle">{{ '@'.$staffMember->username }} · {{ $staffRole }}</div>
                        </div>
                    </a>
                    <form method="POST" action="{{ route('follow.store', $staffMember) }}">
                        @csrf
                        <button type="submit" class="ob-btn ob-btn--primary ob-btn--small">{{ __('openbook.follow.follow') }}</button>
                    </form>
                </div>
            @endforeach
        </section>
    @endif

    @if ($welcomeKit['local']->isNotEmpty())
        <section class="ob-welcome__section" aria-labelledby="ob-welcome-local">
            <h3 id="ob-welcome-local" class="ob-welcome__section-title">{{ __('openbook.feed.welcome_local') }}</h3>
            @foreach ($welcomeKit['local'] as $actor)
                @php
                    $suggestedUser = $actor->user;
                    $suggestedName = $suggestedUser?->profile?->display_name ?: $suggestedUser?->username;
                @endphp
                @if ($suggestedUser)
                    <div class="ob-suggestion">
                        <a href="{{ route('profile.show', $suggestedUser->username) }}" class="ob-mini-profile__link">
                            <x-avatar :user="$suggestedUser" style="width:40px;height:40px" />
                            <div>
                                <div class="ob-post__author">{{ $suggestedName }}</div>
                                <div class="ob-post__handle">{{ '@'.$suggestedUser->username }}</div>
                            </div>
                        </a>
                        <form method="POST" action="{{ route('follow.store', $suggestedUser) }}">
                            @csrf
                            <button type="submit" class="ob-btn ob-btn--ghost ob-btn--small">{{ __('openbook.follow.follow') }}</button>
                        </form>
                    </div>
                @endif
            @endforeach
        </section>
    @endif

    @if ($welcomeKit['remote']->isNotEmpty())
        <section class="ob-welcome__section" aria-labelledby="ob-welcome-remote">
            <h3 id="ob-welcome-remote" class="ob-welcome__section-title">{{ __('openbook.feed.welcome_remote') }}</h3>
            @foreach ($welcomeKit['remote'] as $rowActor)
                @include('world._suggestion', ['rowActor' => $rowActor])
            @endforeach
        </section>
    @endif

    <div class="ob-welcome__actions">
        @if ($hasSuggestions)
            <a href="{{ route('world.discover') }}" class="ob-btn ob-btn--ghost">{{ __('openbook.feed.welcome_world') }}</a>
        @endif
        <a href="{{ route('communities.index') }}" class="ob-btn ob-btn--ghost">{{ __('openbook.feed.welcome_communities') }}</a>
        @unless ($hasSuggestions)
            <p class="ob-field__help">{{ __('openbook.feed.welcome_empty_instance') }}</p>
        @endunless
    </div>
</div>
