@if ($media->isVideo())
    <video
        class="ob-post__media-video"
        src="{{ $media->url() }}"
        autoplay
        loop
        muted
        playsinline
        preload="metadata"
        @if ($media->alt_text) aria-label="{{ $media->alt_text }}" @endif
    ></video>
@else
    <img
        src="{{ $media->displayUrl() }}"
        data-full-src="{{ $media->url() }}"
        alt="{{ $media->alt_text ?: ($altFallback ?? '') }}"
        loading="lazy"
        data-lightbox-trigger
    >
@endif
