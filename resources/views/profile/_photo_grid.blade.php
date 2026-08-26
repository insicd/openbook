@php
    /**
     * Griglia fotografica con scorrimento infinito: la pagina successiva e'
     * in "data-next-url" (?page=N). Senza JavaScript resta un link
     * "Foto successive". L'attributo data-infinite-scroll sta sulla griglia
     * stessa, cosi' le figure della pagina successiva si accodano alle
     * tessere gia' visibili (e restano nel gruppo lightbox).
     *
     * @var \Illuminate\Contracts\Pagination\LengthAwarePaginator|\Illuminate\Support\Collection|null $media
     */
    $paginator = $media ?? collect();
    $hasMedia = ! $paginator->isEmpty();
    $hasMore = $hasMedia
        && method_exists($paginator, 'hasMorePages')
        && $paginator->hasMorePages();
    $nextUrl = $hasMore ? $paginator->nextPageUrl() : null;
@endphp

@if (! $hasMedia)
    <div class="ob-card">
        <div class="ob-empty-state">
            <p>{{ $emptyMessage ?? __('openbook.profile.no_photos_yet') }}</p>
        </div>
    </div>
@else
    <div class="ob-card">
        <div
            id="ob-photo-grid"
            class="ob-photo-grid"
            data-lightbox-group
            data-infinite-scroll
            @if ($nextUrl) data-next-url="{{ $nextUrl }}" @endif
            data-loading-label="{{ __('openbook.profile.infinite_scroll.loading') }}"
            data-end-label="{{ __('openbook.profile.infinite_scroll.end') }}"
            data-error-label="{{ __('openbook.profile.infinite_scroll.error') }}"
        >
            @foreach ($paginator as $item)
                @php
                    $sourcePost = $item->posts->first();
                @endphp
                <figure class="ob-photo-grid__item">
                    @include('media._attachment', ['media' => $item, 'altFallback' => __('openbook.profile.photo_alt')])
                    @if ($sourcePost)
                        <a href="{{ route('posts.show', $sourcePost) }}" class="ob-photo-grid__post">{{ __('openbook.profile.open_photo_post') }}</a>
                    @endif
                </figure>
            @endforeach
        </div>

        @if ($hasMore)
            <noscript>
                <div class="ob-pagination">
                    <a href="{{ $nextUrl }}">{{ __('openbook.profile.infinite_scroll.next') }}</a>
                </div>
            </noscript>
        @endif
    </div>
@endif
