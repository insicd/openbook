@php
    /** @var \App\Domain\Posts\VideoEmbed $embed */
@endphp

<div class="ob-post__video">
    <iframe
        src="{{ $embed->embedUrl }}"
        title="{{ __('openbook.posts.video_embed', ['provider' => $embed->provider]) }}"
        loading="lazy"
        referrerpolicy="strict-origin-when-cross-origin"
        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
        allowfullscreen
    ></iframe>
</div>
