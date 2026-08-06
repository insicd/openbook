@if ($media->isEmpty())
    <div class="ob-card">
        <div class="ob-empty-state">
            <p>{{ $emptyMessage ?? __('openbook.profile.no_photos_yet') }}</p>
        </div>
    </div>
@else
    <div class="ob-card">
        <div class="ob-photo-grid" data-lightbox-group>
            @foreach ($media as $item)
                @php
                    $sourcePost = $item->posts->first();
                @endphp
                <figure class="ob-photo-grid__item">
                    <img
                        src="{{ $item->displayUrl() }}"
                        data-full-src="{{ $item->url() }}"
                        alt="{{ $item->alt_text ?: __('openbook.profile.photo_alt') }}"
                        loading="lazy"
                        data-lightbox-trigger
                    >
                    @if ($sourcePost)
                        <a href="{{ route('posts.show', $sourcePost) }}" class="ob-photo-grid__post">{{ __('openbook.profile.open_photo_post') }}</a>
                    @endif
                </figure>
            @endforeach
        </div>
        {{ $media->links() }}
    </div>
@endif
