@extends('layouts.app')

@section('title', __('openbook.communities.index_title').' - '.config('app.name'))

@section('content')
    <div class="ob-card">
        <div class="ob-profile-actions" style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap">
            <div>
                <h1>{{ __('openbook.communities.index_title') }}</h1>
                <p class="ob-field__help">
                    {{ $scope === 'remote'
                        ? __('openbook.communities.index_subtitle_remote')
                        : __('openbook.communities.index_subtitle') }}
                </p>
            </div>
            @auth
                @can('create', App\Domain\Communities\Community::class)
                    <a href="{{ route('communities.create') }}" class="ob-btn ob-btn--primary">{{ __('openbook.communities.create') }}</a>
                @endcan
            @endauth
        </div>

        <div class="ob-scope-switch" role="tablist" aria-label="{{ __('openbook.communities.scope_aria') }}">
            <a href="{{ route('communities.index') }}"
               class="ob-btn {{ $scope === 'local' ? 'ob-btn--primary' : 'ob-btn--ghost' }}"
               role="tab"
               aria-selected="{{ $scope === 'local' ? 'true' : 'false' }}">{{ __('openbook.communities.scope_local') }}</a>
            <a href="{{ route('communities.index', ['scope' => 'remote']) }}"
               class="ob-btn {{ $scope === 'remote' ? 'ob-btn--primary' : 'ob-btn--ghost' }}"
               role="tab"
               aria-selected="{{ $scope === 'remote' ? 'true' : 'false' }}">{{ __('openbook.communities.scope_remote') }}</a>
        </div>
    </div>

    <div class="ob-card">
        @if ($communities->isEmpty())
            <div class="ob-empty-state">
                @if ($scope === 'remote')
                    <p>{{ auth()->check() ? __('openbook.communities.empty_remote') : __('openbook.communities.empty_remote_guest') }}</p>
                @else
                    <p>{{ __('openbook.communities.empty') }}</p>
                @endif
            </div>
        @elseif ($scope === 'remote')
            <ul class="ob-community-list">
                @foreach ($communities as $actor)
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
                        <span class="ob-badge">{{ __('openbook.communities.remote_badge') }}</span>
                    </li>
                @endforeach
            </ul>
            {{ $communities->links() }}
        @else
            <ul class="ob-community-list">
                @foreach ($communities as $community)
                    <li class="ob-community-list__item">
                        <a href="{{ route('communities.show', $community) }}" class="ob-mini-profile__link">
                            <x-avatar :actor="$community->actor" style="width:48px;height:48px" />
                            <div>
                                <div class="ob-post__author">{{ $community->actor->displayName() }}</div>
                                <div class="ob-post__handle">!{{ $community->slug }}</div>
                                @if (filled($community->actor->summary))
                                    <p class="ob-field__help">{{ \Illuminate\Support\Str::limit($community->actor->summary, 120) }}</p>
                                @endif
                            </div>
                        </a>
                        <span class="ob-field__help">{{ trans_choice('openbook.communities.members_count', $community->members_count, ['count' => $community->members_count]) }}</span>
                    </li>
                @endforeach
            </ul>
            {{ $communities->links() }}
        @endif
    </div>
@endsection
