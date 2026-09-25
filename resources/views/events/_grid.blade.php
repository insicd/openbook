@if ($events->isEmpty() && ! ($fragment ?? false))
    <div class="ob-empty-state"><p>{{ __('openbook.events.empty') }}</p></div>
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
    @if ($events->hasMorePages() && ! ($fragment ?? false))
        <noscript>
            <div class="ob-pagination">
                <a href="{{ $events->nextPageUrl() }}">{{ __('openbook.infinite_scroll.next') }}</a>
            </div>
        </noscript>
    @endif
@endif
