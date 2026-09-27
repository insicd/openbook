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
        @include('communities._directory_page', [
            'initialLoading' => true,
            'initialUrl' => request()->fullUrl(),
        ])
    </div>
    <noscript>
        <div class="ob-card ob-empty-state">
            <p>{{ __('openbook.communities.requires_js') }}</p>
        </div>
    </noscript>
@endsection
