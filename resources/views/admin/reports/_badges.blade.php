<span class="ob-badge">{{ $report->closure_reason === \App\Domain\Moderation\Report::CLOSURE_UNAVAILABLE ? __('openbook.admin.reports.already_handled') : __('openbook.admin.reports.status.'.$report->status) }}</span>
<span class="ob-badge">{{ __('openbook.admin.reports.origin_'.$report->origin) }}</span>
@foreach ($report->targetTypes() as $type)
    <span class="ob-badge">{{ __('openbook.admin.reports.type_'.$type) }}</span>
@endforeach
<span class="ob-badge">{{ __('openbook.reports.reasons.'.$report->reason) }}</span>
