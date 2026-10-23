@extends('layouts.admin')

@section('title', __('openbook.admin.reports.detail_title').' - '.config('app.name'))

@section('content')
    <p><a href="{{ route('admin.reports.index') }}">&larr; {{ __('openbook.admin.reports.back') }}</a></p>
    <h1>{{ __('openbook.admin.reports.detail_title') }}</h1>
    @include('admin.partials.timezone-note')

    <div class="ob-card" style="margin-top:1rem">
        <p>@include('admin.reports._badges', ['report' => $report])</p>
        @if ($report->isFederated())
            <p class="ob-report-uri"><strong>{{ __('openbook.admin.reports.sender') }}:</strong> <bdi>{{ $report->reporter_uri }}</bdi></p>
            <p class="ob-report-uri"><strong>{{ __('openbook.admin.reports.instance') }}:</strong> {{ $report->reporter_domain }}</p>
            <p class="ob-field__help">{{ __('openbook.admin.reports.original_reporter_unknown') }}</p>
        @else
            <p>{{ __('openbook.admin.reports.by', ['name' => $report->reporter?->username ?? '—']) }}</p>
        @endif
        <p class="ob-field__help">@userdate($report->created_at, 'Y-m-d H:i')</p>
        @if ($report->details)
            <p><strong>{{ __('openbook.reports.details_label') }}:</strong></p>
            <div class="ob-report-text">{{ $report->details }}</div>
        @endif
        @if ($report->closure_reason === \App\Domain\Moderation\Report::CLOSURE_UNAVAILABLE)
            <p class="ob-field__help">{{ __('openbook.admin.reports.automatically_handled') }}</p>
        @endif
        @if ($report->closed_at)
            <p class="ob-field__help">{{ __('openbook.admin.reports.closed_on', ['date' => app(\App\Support\UserDate::class)->format($report->closed_at, 'Y-m-d H:i')]) }}</p>
        @endif
        @if ($report->reviewer)
            <p class="ob-field__help">{{ __('openbook.admin.reports.reviewed_by', ['name' => $report->reviewer->username, 'date' => app(\App\Support\UserDate::class)->format($report->reviewed_at, 'Y-m-d H:i')]) }}</p>
        @endif
    </div>

    <h2 style="margin-top:1.5rem">{{ __('openbook.admin.reports.subjects') }}</h2>
    @foreach ($previews as $preview)
        @include('admin.reports._target', ['preview' => $preview])
    @endforeach

    <div class="ob-admin-actions" style="margin-top:1.25rem">
        @if (in_array($report->status, [\App\Domain\Moderation\Report::STATUS_OPEN, \App\Domain\Moderation\Report::STATUS_REVIEWED], true))
            @if ($report->isOpen())
            <form method="POST" action="{{ route('admin.reports.review', $report) }}">
                @csrf
                <button type="submit" class="ob-btn ob-btn--ghost">{{ __('openbook.admin.reports.mark_reviewed') }}</button>
            </form>
            @endif
            <form method="POST" action="{{ route('admin.reports.dismiss', $report) }}">
                @csrf
                <button type="submit" class="ob-btn ob-btn--ghost">{{ __('openbook.admin.reports.dismiss') }}</button>
            </form>
            @unless ($report->isFederated())
            <form method="POST" action="{{ route('admin.reports.action', $report) }}">
                @csrf
                @if ($report->post && ! $report->post->isRemote() && $report->post->isPublished())
                    <label class="ob-field__help" style="display:flex;align-items:center;gap:0.4rem;margin-bottom:0.5rem">
                        <input type="checkbox" name="delete_post" value="1">
                        {{ __('openbook.admin.reports.delete_local_post') }}
                    </label>
                @endif
                @if ($report->comment && ! $report->comment->isRemote() && $report->comment->status === \App\Domain\Comments\Comment::STATUS_PUBLISHED)
                    <label class="ob-field__help" style="display:flex;align-items:center;gap:0.4rem;margin-bottom:0.5rem">
                        <input type="checkbox" name="delete_comment" value="1">
                        {{ __('openbook.admin.reports.delete_local_comment') }}
                    </label>
                @endif
                <button type="submit" class="ob-btn ob-btn--primary">{{ __('openbook.admin.reports.mark_actioned') }}</button>
            </form>
            @endunless
        @endif
    </div>
@endsection
