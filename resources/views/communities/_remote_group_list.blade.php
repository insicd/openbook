@php
    /** @var \Illuminate\Support\Collection<int, \App\Federation\Actors\Actor> $actors */
    $showActions = $showActions ?? false;
    $statusMap = $statusMap ?? [];
@endphp

<ul class="ob-community-list">
    @foreach ($actors as $actor)
        @php
            $localMembers = (int) ($actor->local_members_count ?? 0);
            $status = $statusMap[$actor->id] ?? ['following' => false, 'pending' => false];
        @endphp
        <li class="ob-community-list__item">
            <a href="{{ $actor->profileUrl() }}" class="ob-mini-profile__link">
                <x-avatar :actor="$actor" style="width:48px;height:48px" />
                <div>
                    <div class="ob-post__author">{{ $actor->displayName() }}</div>
                    <div class="ob-post__handle">!{{ $actor->handle() }}</div>
                    @if (filled($actor->summary))
                        <p class="ob-field__help">{{ \Illuminate\Support\Str::limit(strip_tags($actor->summary), 120) }}</p>
                    @endif
                </div>
            </a>
            <div class="ob-community-list__meta">
                @if ($localMembers > 0)
                    <span class="ob-field__help">{{ trans_choice('openbook.communities.local_instance_members', $localMembers, ['count' => $localMembers]) }}</span>
                @endif
                @if ($showActions)
                    @auth
                        @if ($status['following'])
                            <form method="POST" action="{{ route('actors.unfollow', $actor) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="ob-btn ob-btn--ghost ob-btn--small">{{ __('openbook.communities.leave') }}</button>
                            </form>
                        @elseif ($status['pending'])
                            <form method="POST" action="{{ route('actors.unfollow', $actor) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="ob-btn ob-btn--ghost ob-btn--small">{{ __('openbook.communities.pending') }}</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('actors.follow', $actor) }}">
                                @csrf
                                <button type="submit" class="ob-btn ob-btn--primary ob-btn--small">{{ __('openbook.communities.join') }}</button>
                            </form>
                        @endif
                    @else
                        <a href="{{ route('login') }}" class="ob-btn ob-btn--primary ob-btn--small">{{ __('openbook.communities.join') }}</a>
                    @endauth
                @else
                    <span class="ob-badge">{{ __('openbook.communities.remote_badge') }}</span>
                @endif
            </div>
        </li>
    @endforeach
</ul>
