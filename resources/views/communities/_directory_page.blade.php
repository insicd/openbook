@php
    $nextUrl = $initialUrl ?? (($communities ?? null)?->hasMorePages() ? $communities->nextPageUrl() : null);
@endphp
<ul
    class="ob-community-list"
    data-infinite-scroll
    data-async-feed
    data-retry-label="{{ __('openbook.infinite_scroll.retry') }}"
    @if ($initialLoading ?? false) data-initial-load @endif
    @if ($nextUrl) data-next-url="{{ $nextUrl }}" @endif
    data-loading-label="{{ __('openbook.infinite_scroll.loading') }}"
    data-error-label="{{ __('openbook.infinite_scroll.retry_error') }}"
>
    @unless ($initialLoading ?? false)
        @if ($scope === 'mine' && auth()->guest())
            <li class="ob-empty-state">
                <p>{{ __('openbook.communities.mine_login_prompt') }} <a href="{{ route('login') }}">{{ __('openbook.nav.login') }}</a></p>
            </li>
        @elseif ($communities->isEmpty())
            <li class="ob-empty-state"><p>{{ __('openbook.communities.empty_'.$scope) }}</p></li>
        @else
            @include('communities._directory_list', ['actors' => $communities, 'statusMap' => $statusMap])
        @endif
    @endunless
</ul>
