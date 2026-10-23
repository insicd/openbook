@extends('layouts.admin')

@section('title', __('openbook.admin.reports.title').' - '.config('app.name'))

@section('content')
    <h1>{{ __('openbook.admin.reports.title') }}</h1>

    <div class="ob-admin-filters">
        @foreach (['open' => __('openbook.admin.reports.status.open'), 'reviewed' => __('openbook.admin.reports.status.reviewed'), 'dismissed' => __('openbook.admin.reports.status.dismissed'), 'actioned' => __('openbook.admin.reports.status.actioned'), 'all' => __('openbook.admin.reports.status.all')] as $key => $label)
            <a href="{{ route('admin.reports.index', ['status' => $key]) }}" class="ob-btn {{ $status === $key ? 'ob-btn--primary' : 'ob-btn--ghost' }}">{{ $label }}</a>
        @endforeach
    </div>

    @forelse ($reports as $report)
        <article class="ob-card" style="margin-top:1rem">
            <div class="ob-admin-row">
                <div>
                    <a href="{{ route('admin.reports.show', $report) }}"><strong>#{{ \Illuminate\Support\Str::limit($report->id, 8, '') }}</strong></a>
                    @include('admin.reports._badges', ['report' => $report])
                    <p class="ob-field__help ob-report-uri" style="margin:0.4rem 0 0">
                        @if ($report->isFederated())
                            {{ __('openbook.admin.reports.sender') }}: {{ $report->reporter_uri }}
                            · {{ $report->reporter_domain }}
                        @else
                            {{ __('openbook.admin.reports.by', ['name' => $report->reporter?->username ?? '—']) }}
                        @endif
                        &middot; {{ $report->created_at->diffForHumans() }}
                    </p>
                </div>
                <a href="{{ route('admin.reports.show', $report) }}" class="ob-btn ob-btn--ghost">{{ __('openbook.admin.reports.view') }}</a>
            </div>
        </article>
    @empty
        <div class="ob-empty-state" style="margin-top:1.5rem">
            <p>{{ __('openbook.admin.reports.empty') }}</p>
        </div>
    @endforelse

    <div style="margin-top:1.5rem">{{ $reports->links() }}</div>
@endsection
