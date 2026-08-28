@php
    /**
     * Stream di attivita' del profilo con scorrimento infinito (?page=N).
     * Senza JavaScript resta un link "Attivita' successive".
     *
     * @var \App\Application\Queries\ActivityPage|null $activity
     */
    $hasItems = isset($activity) && $activity !== null && ! $activity->isEmpty();
    $nextUrl = $hasItems && $activity->hasMorePages() ? $activity->nextPageUrl() : null;
@endphp

@if (! empty($activityNotice))
    <div class="ob-alert ob-alert--info" role="note">
        {{ $activityNotice }}
    </div>
@endif

@if (! $hasItems)
    <div class="ob-card">
        <div class="ob-empty-state">
            <p>{{ $emptyMessage ?? __('openbook.profile.no_activity_yet') }}</p>
        </div>
    </div>
@else
    <div class="ob-card">
        <div
            id="ob-activity-list"
            data-infinite-scroll
            @if ($nextUrl) data-next-url="{{ $nextUrl }}" @endif
            data-loading-label="{{ __('openbook.profile.activity_scroll.loading') }}"
            data-end-label="{{ __('openbook.profile.activity_scroll.end') }}"
            data-error-label="{{ __('openbook.profile.activity_scroll.error') }}"
        >
            @foreach ($activity as $item)
                @include('profile._activity_item', ['item' => $item])
            @endforeach
        </div>

        @if ($nextUrl)
            <noscript>
                <div class="ob-pagination">
                    <a href="{{ $nextUrl }}">{{ __('openbook.profile.activity_scroll.next') }}</a>
                </div>
            </noscript>
        @endif
    </div>
@endif
