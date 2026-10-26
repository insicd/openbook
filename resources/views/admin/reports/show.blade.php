@extends('layouts.admin')

@section('title', __('openbook.admin.reports.detail_title').' - '.config('app.name'))

@section('content')
    @php
        $hasDeletionOptions = $report->isFederated()
            ? collect($previews)->contains(fn ($preview) => $preview['can_delete'])
            : collect($previews)->contains(fn ($preview) => $preview['state'] === 'available')
                && (($report->post && ! $report->post->isRemote() && $report->post->isPublished())
                    || ($report->comment && ! $report->comment->isRemote() && $report->comment->isPublished()));
        $canAct = in_array($report->status, [\App\Domain\Moderation\Report::STATUS_OPEN, \App\Domain\Moderation\Report::STATUS_REVIEWED], true);
    @endphp
    <div class="ob-report-page" data-report-page>
        <header class="ob-report-heading">
            <a href="{{ route('admin.reports.index') }}">&larr; {{ __('openbook.admin.reports.back') }}</a>
            <h1>{{ __('openbook.admin.reports.detail_title') }}</h1>
            <div class="ob-report-badges">@include('admin.reports._badges', ['report' => $report, 'detail' => true])</div>
        </header>

        <div class="ob-report-layout">
            <div class="ob-report-content">
                <section class="ob-card ob-report-section" aria-labelledby="report-motivation">
                    <h2 id="report-motivation">{{ __('openbook.admin.reports.motivation') }}</h2>
                    @if ($report->details)
                        <div class="ob-report-text ob-report-motivation">{{ $report->details }}</div>
                    @else
                        <p class="ob-field__help">{{ __('openbook.admin.reports.no_motivation') }}</p>
                    @endif
                    <div class="ob-report-source">
                        <dl class="ob-report-facts">
                            @if ($report->isFederated())
                                <div><dt>{{ __('openbook.admin.reports.instance') }}</dt><dd>{{ $report->reporter_domain }}</dd></div>
                                <div><dt>{{ __('openbook.admin.reports.sender') }}</dt><dd class="ob-report-uri"><bdi>{{ $report->reporter_uri }}</bdi></dd></div>
                            @else
                                <div><dt>{{ __('openbook.admin.reports.reported_by') }}</dt><dd>{{ $report->reporter?->username ?? '—' }}</dd></div>
                            @endif
                            <div><dt>{{ __('openbook.admin.reports.received_on') }}</dt><dd>@userdate($report->created_at, 'Y-m-d H:i')</dd></div>
                        </dl>
                        @if ($report->isFederated())
                            <details class="ob-report-disclosure">
                                <summary>{{ __('openbook.admin.reports.about_sender') }}</summary>
                                <p class="ob-field__help">{{ __('openbook.admin.reports.original_reporter_unknown') }}</p>
                            </details>
                        @endif
                    </div>
                </section>

                <section aria-labelledby="report-subjects">
                    <div class="ob-report-section-heading">
                        <h2 id="report-subjects">{{ __('openbook.admin.reports.subjects') }}</h2>
                        <span class="ob-badge">{{ count($previews) }}</span>
                    </div>
                    <div class="ob-report-targets">
                        @foreach ($previews as $preview)
                            @include('admin.reports._target', ['preview' => $preview])
                        @endforeach
                    </div>
                </section>
            </div>

            <aside class="ob-card ob-report-workflow" aria-labelledby="report-workflow">
                <h2 id="report-workflow">{{ __('openbook.admin.reports.case_management') }}</h2>
                @if ($canAct)
                    <p class="ob-field__help ob-report-workflow-intro">{{ __('openbook.admin.reports.case_management_help') }}</p>
                    @if ($report->isOpen())
                        <form method="POST" action="{{ route('admin.reports.review', $report) }}" class="ob-report-step">
                            @csrf
                            <button type="submit" class="ob-btn ob-btn--ghost" title="{{ __('openbook.admin.reports.review_help') }}" aria-describedby="report-review-help">{{ __('openbook.admin.reports.mark_reviewed') }}</button>
                            <p id="report-review-help" class="ob-field__help">{{ __('openbook.admin.reports.review_help') }}</p>
                        </form>
                    @else
                        <p class="ob-report-reviewed">{{ __('openbook.admin.reports.reviewed_pending') }}</p>
                    @endif
                    <form method="POST" action="{{ route('admin.reports.dismiss', $report) }}" class="ob-report-step">
                        @csrf
                        <button type="submit" class="ob-btn ob-btn--ghost" title="{{ __('openbook.admin.reports.dismiss_help') }}" aria-describedby="report-dismiss-help">{{ __('openbook.admin.reports.dismiss') }}</button>
                        <p id="report-dismiss-help" class="ob-field__help">{{ __('openbook.admin.reports.dismiss_help') }}</p>
                    </form>
                    <form id="report-action" method="POST" action="{{ route('admin.reports.action', $report) }}" class="ob-report-step ob-report-complete"
                        data-close-label="{{ __('openbook.admin.reports.close_handled') }}"
                        data-delete-label="{{ __('openbook.admin.reports.delete_and_close') }}"
                        data-empty-help="{{ __('openbook.admin.reports.close_help') }}"
                        data-selected-help="{{ __('openbook.admin.reports.selected_help') }}">
                        @csrf
                        <p id="report-action-help" class="ob-field__help" data-report-selection aria-live="polite">{{ __('openbook.admin.reports.'.($hasDeletionOptions ? 'action_help' : 'close_help')) }}</p>
                        <button type="submit" class="ob-btn ob-btn--primary" data-report-submit aria-describedby="report-action-help" title="{{ __('openbook.admin.reports.'.($hasDeletionOptions ? 'action_help' : 'close_help')) }}">{{ __('openbook.admin.reports.'.($hasDeletionOptions ? 'mark_actioned' : 'close_handled')) }}</button>
                    </form>
                @else
                    <p class="ob-field__help">{{ __('openbook.admin.reports.case_closed') }}</p>
                    @if ($report->closure_reason === \App\Domain\Moderation\Report::CLOSURE_UNAVAILABLE)
                        <p class="ob-report-outcome">{{ __('openbook.admin.reports.automatically_handled') }}</p>
                    @endif
                @endif
                @if ($report->closed_at || $report->reviewer)
                    <div class="ob-report-history">
                        @if ($report->closed_at)
                            <p>{{ __('openbook.admin.reports.closed_on', ['date' => app(\App\Support\UserDate::class)->format($report->closed_at, 'Y-m-d H:i')]) }}</p>
                        @endif
                        @if ($report->reviewer)
                            <p>{{ __('openbook.admin.reports.reviewed_by', ['name' => $report->reviewer->username, 'date' => app(\App\Support\UserDate::class)->format($report->reviewed_at, 'Y-m-d H:i')]) }}</p>
                        @endif
                    </div>
                @endif
                <div class="ob-report-timezone">@include('admin.partials.timezone-note')</div>
            </aside>
        </div>
    </div>
    <script src="{{ \App\Support\Assets::url('assets/js/report-moderation.js') }}" defer></script>
@endsection
