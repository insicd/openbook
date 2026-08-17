@php
    /**
     * Corpo visibile di un post: testo, embed, allegati, hashtag e citazione.
     * Quando c'e' un avviso sul contenuto, questo blocco sta dentro il soffietto.
     *
     * @var \App\Domain\Posts\Post $post
     * @var int $embedDepth
     * @var bool $truncateBody
     */
@endphp

@include('posts._body', ['body' => $post->body, 'truncateBody' => $truncateBody])
@include('posts._video_embed_if_any', ['body' => $post->body])

@if ($post->media->isNotEmpty())
    <div class="ob-post__media" data-lightbox-group>
        @foreach ($post->media as $media)
            @include('media._attachment', ['media' => $media])
        @endforeach
    </div>
@endif

@if ($post->hashtags->isNotEmpty())
    <p class="ob-post__hashtags">
        @foreach ($post->hashtags as $hashtag)
            <a href="{{ route('hashtags.show', $hashtag->name) }}">#{{ $hashtag->name }}</a>
        @endforeach
    </p>
@endif

@if ($post->quotedPost && $embedDepth < 1)
    <div class="ob-post__quote">
        @include('posts._card', [
            'post' => $post->quotedPost,
            'embed' => true,
            'embedDepth' => $embedDepth + 1,
            'linkToPost' => true,
            'truncateBody' => $truncateBody,
        ])
    </div>
@endif
