@php
    /**
     * Elenco di post con scorrimento infinito: la pagina successiva e'
     * indicata da "data-next-url" con cursore (?cursor=...) ancorato
     * all'ultimo post mostrato, cosi' i nuovi post in cima non duplicano
     * voci gia' caricate. Le pagine complete degli altri elenchi mantengono
     * un link "Post successivi" senza JavaScript.
     *
     * @var \App\Application\Queries\FeedPage|null $posts
     * @var string $emptyMessage
     */
    $hasPosts = isset($posts) && $posts !== null;
    $nextUrl = $initialUrl ?? ($hasPosts && $posts->hasMorePages() ? $posts->nextPageUrl() : null);
@endphp

<div
    id="ob-post-list"
    @if ($homeFeed ?? false) class="ob-home-feed" @elseif ($asyncFeed ?? false) class="ob-world-feed" @endif
    data-infinite-scroll
    @if ($homeFeed ?? false) data-home-feed @endif
    @if (($homeFeed ?? false) || ($asyncFeed ?? false)) data-async-feed data-retry-label="{{ __('openbook.infinite_scroll.retry') }}" @endif
    @if ($initialLoading ?? false) data-initial-load @endif
    @if ($nextUrl) data-next-url="{{ $nextUrl }}" @endif
    data-loading-label="{{ __('openbook.infinite_scroll.loading') }}"
    data-end-label="{{ __('openbook.infinite_scroll.end') }}"
    data-error-label="{{ (($homeFeed ?? false) || ($asyncFeed ?? false)) ? __('openbook.infinite_scroll.retry_error') : __('openbook.infinite_scroll.error') }}"
>
    @if (! ($initialLoading ?? false))
        @forelse ($hasPosts ? $posts : [] as $post)
            @include('posts._card', ['post' => $post, 'truncateBody' => true])
        @empty
            @if (($welcomeKit ?? null) !== null)
                @include('feed._welcome', ['welcomeKit' => $welcomeKit])
            @else
                <div class="ob-card">
                    <div class="ob-empty-state">
                        <p>{{ $emptyMessage }}</p>
                    </div>
                </div>
            @endif
        @endforelse
    @endif
</div>

@if ($hasPosts && $posts->hasMorePages() && ! ($fragment ?? false))
    <noscript>
        <div class="ob-pagination">
            <a href="{{ $posts->nextPageUrl() }}">{{ __('openbook.infinite_scroll.next') }}</a>
        </div>
    </noscript>
@endif
