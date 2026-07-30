@php
    $embed = \App\Domain\Posts\VideoEmbedFinder::first($body ?? '');
@endphp

@if ($embed)
    @include('posts._video_embed', ['embed' => $embed])
@endif
