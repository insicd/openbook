@php
    /**
     * Elenco di post con scorrimento infinito: sostituisce la paginazione a
     * numeri di pagina (che restava comunque disponibile via "?page=N", ma
     * senza JavaScript). Ogni pagina che include questo parziale ha, al
     * massimo, un solo elenco di questo tipo: l'id fisso "ob-post-list" e'
     * quindi sufficiente, non serve generarne uno univoco.
     *
     * @var \Illuminate\Pagination\LengthAwarePaginator|null $posts
     * @var string $emptyMessage
     */
    $hasPosts = isset($posts) && $posts !== null;
    $nextUrl = $hasPosts && $posts->hasPages() ? $posts->nextPageUrl() : null;
@endphp

<div
    id="ob-post-list"
    data-infinite-scroll
    @if ($nextUrl) data-next-url="{{ $nextUrl }}" @endif
    data-loading-label="{{ __('openbook.infinite_scroll.loading') }}"
    data-end-label="{{ __('openbook.infinite_scroll.end') }}"
    data-error-label="{{ __('openbook.infinite_scroll.error') }}"
>
    @forelse ($hasPosts ? $posts : [] as $post)
        @include('posts._card', ['post' => $post])
    @empty
        <div class="ob-card">
            <div class="ob-empty-state">
                <p>{{ $emptyMessage }}</p>
            </div>
        </div>
    @endforelse
</div>

@if ($hasPosts && $posts->hasPages())
    <noscript>
        <div class="ob-pagination">
            {{ $posts->onEachSide(1)->links() }}
        </div>
    </noscript>
@endif
