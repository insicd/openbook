@extends('layouts.admin')

@section('title', __('openbook.admin.dashboard.title').' - '.config('app.name'))

@section('content')
    <h1>{{ __('openbook.admin.dashboard.title') }}</h1>
    <p class="ob-field__help">{{ __('openbook.admin.dashboard.intro') }}</p>

    <div class="ob-admin-stats">
        <a href="{{ route('admin.reports.index', ['status' => 'open']) }}" class="ob-card ob-admin-stat">
            <strong>{{ $openReportsCount }}</strong>
            <span>{{ __('openbook.admin.dashboard.open_reports') }}</span>
        </a>
        <a href="{{ route('admin.users.index') }}" class="ob-card ob-admin-stat">
            <strong>{{ $localUsersCount }}</strong>
            <span>{{ __('openbook.admin.dashboard.local_users') }}</span>
        </a>
        <div class="ob-card ob-admin-stat">
            <strong>{{ $suspendedUsersCount }}</strong>
            <span>{{ __('openbook.admin.dashboard.suspended_users') }}</span>
        </div>
        <div class="ob-card ob-admin-stat">
            <strong>{{ $moderatorCount }}</strong>
            <span>{{ __('openbook.admin.dashboard.moderators') }}</span>
        </div>
        @can('administer')
            <a href="{{ route('admin.queue.index') }}" class="ob-card ob-admin-stat">
                <strong>{{ $failedJobsCount }}</strong>
                <span>{{ __('openbook.admin.dashboard.failed_jobs') }}</span>
            </a>
            <div class="ob-card ob-admin-stat">
                <strong>{{ $pendingInboxCount }}</strong>
                <span>{{ __('openbook.admin.dashboard.pending_inbox') }}</span>
            </div>
        @endcan
    </div>
@endsection
