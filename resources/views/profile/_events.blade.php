<div class="ob-card">
    @if ($isOwnProfile ?? false)
        <div class="ob-profile-events__create">
            <a href="{{ route('events.create') }}" class="ob-btn ob-btn--primary">{{ __('openbook.events.composer.create') }}</a>
        </div>
    @endif
    <div class="ob-scope-switch" role="tablist">
        <a href="{{ $eventsUrl }}" class="ob-btn {{ !$eventsArchive ? 'ob-btn--primary' : 'ob-btn--ghost' }}" role="tab" aria-selected="{{ !$eventsArchive ? 'true' : 'false' }}">
            {{ __('openbook.events.upcoming') }}
        </a>
        <a href="{{ $eventsUrl }}?archivio=1" class="ob-btn {{ $eventsArchive ? 'ob-btn--primary' : 'ob-btn--ghost' }}" role="tab" aria-selected="{{ $eventsArchive ? 'true' : 'false' }}">
            {{ __('openbook.events.archive') }}
        </a>
    </div>

    @if ($events->isEmpty())
        <div class="ob-empty-state"><p>{{ __('openbook.profile.no_events_yet') }}</p></div>
    @else
        <div
            class="ob-event-grid"
            data-infinite-scroll
            @if ($events->hasMorePages()) data-next-url="{{ $events->nextPageUrl() }}" @endif
            data-loading-label="{{ __('openbook.events.infinite_scroll.loading') }}"
            data-end-label="{{ __('openbook.events.infinite_scroll.end') }}"
            data-error-label="{{ __('openbook.events.infinite_scroll.error') }}"
        >
            @foreach ($events as $event)
                @include('events._card', ['event' => $event])
            @endforeach
        </div>
        @if ($events->hasMorePages())
            <noscript>
                <div class="ob-pagination">
                    <a href="{{ $events->nextPageUrl() }}">{{ __('openbook.events.infinite_scroll.next') }}</a>
                </div>
            </noscript>
        @endif
    @endif
</div>
