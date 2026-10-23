<article class="ob-card ob-report-target" data-report-target="{{ $preview['type'] }}">
    <span class="ob-badge">{{ __('openbook.admin.reports.type_'.$preview['type']) }}</span>
    <p class="ob-field__help ob-report-uri">{{ $preview['uri'] }}</p>
    @if ($preview['state'] === 'missing')
        <p class="ob-field__help">{{ __('openbook.admin.reports.target_missing') }}</p>
    @elseif ($preview['state'] === 'restricted')
        <p class="ob-field__help">{{ __('openbook.admin.reports.target_restricted') }}</p>
    @else
        @if ($preview['author'])
            <p class="ob-field__help">{{ __('openbook.admin.reports.subject_author', ['name' => $preview['author']]) }}</p>
        @endif
        @if ($preview['title'])
            <h3>{{ $preview['title'] }}</h3>
        @endif
        @if ($preview['body'])
            <div class="ob-report-text">{{ $preview['body'] }}</div>
        @endif
        <p><a class="ob-btn ob-btn--ghost" href="{{ $preview['url'] }}">{{ __('openbook.admin.reports.open_subject') }}</a></p>
    @endif
</article>
