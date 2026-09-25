<div
    id="ob-discover-list"
    data-infinite-scroll
    @if ($suggestedActors->hasMorePages()) data-next-url="{{ $suggestedActors->nextPageUrl() }}" @endif
    data-loading-label="{{ __('openbook.world.infinite_scroll.loading') }}"
    data-end-label="{{ __('openbook.world.infinite_scroll.end') }}"
    data-error-label="{{ __('openbook.world.infinite_scroll.error') }}"
>
    @forelse ($suggestedActors as $rowActor)
        @include('world._suggestion', ['rowActor' => $rowActor])
    @empty
        @unless ($fragment ?? false)
            <div class="ob-empty-state">
                <p>{{ __('openbook.world.discover_empty') }}</p>
            </div>
        @endunless
    @endforelse
</div>

@if ($suggestedActors->hasPages() && ! ($fragment ?? false))
    <noscript>
        <div class="ob-pagination">
            {{ $suggestedActors->onEachSide(1)->links() }}
        </div>
    </noscript>
@endif
