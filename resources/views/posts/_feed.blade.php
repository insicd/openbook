@php
    /**
     * Elenco di post con scorrimento infinito: la pagina successiva e'
     * indicata da "data-next-url" con cursore (?cursor=...) ancorato
     * all'ultimo post mostrato, cosi' i nuovi post in cima non duplicano
     * voci gia' caricate. Senza JavaScript resta un link "Post successivi".
     *
     * @var \App\Application\Queries\FeedPage|null $posts
     * @var string $emptyMessage
     */
    $hasPosts = isset($posts) && $posts !== null;
    $nextUrl = $hasPosts && $posts->hasMorePages() ? $posts->nextPageUrl() : null;
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
        @include('posts._card', ['post' => $post, 'truncateBody' => true])
    @empty
        <div class="ob-card">
            <div class="ob-empty-state">
                <p>{{ $emptyMessage }}</p>
            </div>
        </div>
    @endforelse
</div>

@if ($hasPosts && $posts->hasMorePages())
    <noscript>
        <div class="ob-pagination">
            <a href="{{ $posts->nextPageUrl() }}">{{ __('openbook.infinite_scroll.next') }}</a>
        </div>
    </noscript>
@endif
