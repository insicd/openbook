@extends('layouts.app')

@section('title', __('openbook.communities.index_title').' - '.config('app.name'))

@section('content')
    <div class="ob-card">
        <div class="ob-profile-actions" style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap">
            <div>
                <h1>{{ __('openbook.communities.index_title') }}</h1>
                <p class="ob-field__help">{{ __('openbook.communities.index_subtitle_'.$scope) }}</p>
            </div>
            @auth
                @can('create', App\Domain\Communities\Community::class)
                    <a href="{{ route('communities.create') }}" class="ob-btn ob-btn--primary">{{ __('openbook.communities.create') }}</a>
                @endcan
            @endauth
        </div>

        <nav class="ob-scope-switch" aria-label="{{ __('openbook.communities.scope_aria') }}">
            @foreach (['mine', 'local', 'remote'] as $tab)
                <a href="{{ route('communities.index', ['scope' => $tab]) }}"
                   class="ob-btn {{ $scope === $tab ? 'ob-btn--primary' : 'ob-btn--ghost' }}"
                   @if ($scope === $tab) aria-current="page" @endif>{{ __('openbook.communities.scope_'.$tab) }}</a>
            @endforeach
        </nav>
    </div>

    <div class="ob-card">
        @if ($scope === 'mine' && auth()->guest())
            <div class="ob-empty-state">
                <p>{{ __('openbook.communities.mine_login_prompt') }} <a href="{{ route('login') }}">{{ __('openbook.nav.login') }}</a></p>
            </div>
        @elseif ($communities->isEmpty())
            <div class="ob-empty-state">
                <p>{{ __('openbook.communities.empty_'.$scope) }}</p>
            </div>
        @else
            @include('communities._directory_list', ['actors' => $communities, 'statusMap' => $statusMap])
            {{ $communities->links() }}
        @endif
    </div>
@endsection
