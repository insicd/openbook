<article class="ob-card ob-report-target" data-report-target="{{ $preview['type'] }}">
    <div class="ob-report-target-heading">
        <span class="ob-badge">{{ __('openbook.admin.reports.type_'.$preview['type']) }}</span>
        @if ($preview['author'] && $preview['type'] !== 'actor')
            <span class="ob-field__help">{{ __('openbook.admin.reports.subject_author', ['name' => $preview['author']]) }}</span>
        @endif
    </div>
    @if ($preview['state'] === 'missing')
        <p class="ob-report-notice">{{ __('openbook.admin.reports.target_missing') }}</p>
    @elseif ($preview['state'] === 'restricted')
        <p class="ob-report-notice">{{ __('openbook.admin.reports.target_restricted') }}</p>
    @else
        @if ($preview['title'])
            <h3>{{ $preview['title'] }}</h3>
        @elseif ($preview['type'] === 'actor' && $preview['author'])
            <h3>{{ $preview['author'] }}</h3>
        @endif
        @if ($preview['body'])
            <div class="ob-report-text">{{ $preview['body'] }}</div>
        @endif
        <div class="ob-report-target-actions">
            <a class="ob-btn ob-btn--ghost" href="{{ $preview['url'] }}" title="{{ __('openbook.admin.reports.open_help') }}">{{ __('openbook.admin.reports.'.($preview['type'] === 'actor' ? 'open_profile' : 'open_subject')) }}</a>
            @if ($preview['manage_user_url'])
                <a class="ob-btn ob-btn--ghost" href="{{ $preview['manage_user_url'] }}" title="{{ __('openbook.admin.reports.manage_user_help') }}">{{ __('openbook.admin.reports.manage_user') }}</a>
            @endif
        </div>
    @endif
    <details class="ob-report-disclosure">
        <summary>{{ __('openbook.admin.reports.original_uri') }}</summary>
        <p class="ob-field__help ob-report-uri">{{ $preview['uri'] }}</p>
    </details>
    @if ($canAct && $preview['state'] === 'available')
        @php
            $deleteName = $report->isFederated() ? 'targets[]' : ($preview['type'] === 'post' ? 'delete_post' : 'delete_comment');
            $canDelete = $report->isFederated() ? $preview['can_delete']
                : ($preview['type'] === 'post' ? $report->post && ! $report->post->isRemote() && $report->post->isPublished()
                    : $report->comment && ! $report->comment->isRemote() && $report->comment->isPublished());
        @endphp
        @if ($canDelete)
            <div class="ob-report-delete-option">
                <label>
                    <input type="checkbox" form="report-action" name="{{ $deleteName }}" value="{{ $report->isFederated() ? $preview['target_id'] : '1' }}" data-report-delete>
                    <span>{{ __('openbook.admin.reports.delete_selected') }}</span>
                </label>
                @if ($preview['type'] === 'event')
                    <p class="ob-field__help">{{ __('openbook.admin.reports.delete_event_help') }}</p>
                @endif
            </div>
        @endif
    @endif
</article>
