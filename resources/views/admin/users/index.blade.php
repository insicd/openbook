@extends('layouts.admin')

@section('title', __('openbook.admin.users.title').' - '.config('app.name'))

@section('content')
    <h1>{{ __('openbook.admin.users.title') }}</h1>
    <p class="ob-field__help">{{ __('openbook.admin.users.status_help') }}</p>

    <form method="GET" action="{{ route('admin.users.index') }}" class="ob-admin-search">
        <input type="search" name="q" value="{{ $q }}" placeholder="{{ __('openbook.admin.users.search_placeholder') }}">
        <button type="submit" class="ob-btn ob-btn--primary">{{ __('openbook.admin.users.search') }}</button>
    </form>

    @foreach ($users as $user)
        <article class="ob-card" style="margin-top:1rem">
            <div class="ob-admin-row">
                <div>
                    <strong><a href="{{ route('profile.show', $user->username) }}">{{ '@'.$user->username }}</a></strong>
                    <span class="ob-field__help">{{ $user->email }}</span>
                    <div style="margin-top:0.35rem;display:flex;gap:0.35rem;flex-wrap:wrap">
                        <span class="ob-badge">{{ __('openbook.admin.users.status.'.$user->status) }}</span>
                        @if ($user->is_admin)
                            <span class="ob-badge">{{ __('openbook.admin.users.role.admin') }}</span>
                        @elseif ($user->is_moderator)
                            <span class="ob-badge">{{ __('openbook.admin.users.role.moderator') }}</span>
                        @else
                            <span class="ob-badge">{{ __('openbook.admin.users.role.user') }}</span>
                        @endif
                    </div>
                </div>
                @if (auth()->id() !== $user->id)
                    <div class="ob-admin-actions">
                        @if ($user->is_admin)
                            @can('administer')
                                <form method="POST" action="{{ route('admin.users.demote_admin', $user) }}" onsubmit="return confirm('{{ __('openbook.admin.users.confirm_demote_admin') }}')">
                                    @csrf
                                    <button type="submit" class="ob-btn ob-btn--ghost">{{ __('openbook.admin.users.demote_admin') }}</button>
                                </form>
                            @endcan
                        @else
                            @php
                                $canChangeStatus = ! $user->is_moderator || auth()->user()->canAdminister();
                            @endphp
                            @if ($canChangeStatus)
                                @if ($user->status === \App\Domain\Accounts\User::STATUS_ACTIVE)
                                    <form method="POST" action="{{ route('admin.users.suspend', $user) }}">
                                        @csrf
                                        <button type="submit" class="ob-btn ob-btn--ghost">{{ __('openbook.admin.users.suspend') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.users.disable', $user) }}" onsubmit="return confirm('{{ __('openbook.admin.users.confirm_disable') }}')">
                                        @csrf
                                        <button type="submit" class="ob-btn ob-btn--ghost">{{ __('openbook.admin.users.disable') }}</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('admin.users.unsuspend', $user) }}">
                                        @csrf
                                        <button type="submit" class="ob-btn ob-btn--ghost">{{ __('openbook.admin.users.unsuspend') }}</button>
                                    </form>
                                @endif
                            @endif
                            @can('administer')
                                @if ($user->is_moderator)
                                    <form method="POST" action="{{ route('admin.users.demote_moderator', $user) }}">
                                        @csrf
                                        <button type="submit" class="ob-btn ob-btn--ghost">{{ __('openbook.admin.users.demote_mod') }}</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('admin.users.promote_moderator', $user) }}">
                                        @csrf
                                        <button type="submit" class="ob-btn ob-btn--primary">{{ __('openbook.admin.users.promote_mod') }}</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('admin.users.promote_admin', $user) }}" onsubmit="return confirm('{{ __('openbook.admin.users.confirm_promote_admin') }}')">
                                    @csrf
                                    <button type="submit" class="ob-btn ob-btn--ghost">{{ __('openbook.admin.users.promote_admin') }}</button>
                                </form>
                            @endcan
                        @endif
                    </div>
                @endif
            </div>
        </article>
    @endforeach

    <div style="margin-top:1.5rem">{{ $users->links() }}</div>
@endsection
