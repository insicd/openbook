@php
    /** @var \App\Domain\Posts\Post $post */
    $author = $post->actor;
    $displayName = $author?->displayName();
    $handle = $author ? '@'.$author->handle() : '';
    $isDeleted = $post->status === \App\Domain\Posts\Post::STATUS_DELETED;
    $linkToPost = $linkToPost ?? true;
    $sharedBy = $post->sharedBy ?? null;
@endphp

<article class="ob-card ob-post">
    @if ($sharedBy)
        <div class="ob-post__shared-by">
            <x-icon name="share" />
            <a href="{{ $sharedBy->profileUrl() }}">{{ $sharedBy->displayName() }}</a>
            {{ __('openbook.actions.shared_this') }}
        </div>
    @endif

    <div class="ob-post__header">
        <x-avatar :actor="$author" />
        <div class="ob-post__meta">
            @if ($author)
                <a href="{{ $author->profileUrl() }}" class="ob-post__author">{{ $displayName }}</a>
                <div class="ob-post__handle">{{ $handle }}</div>
            @endif
            <div class="ob-post__time">
                @if ($linkToPost)
                    <a href="{{ route('posts.show', $post) }}">{{ $post->published_at->diffForHumans() }}</a>
                @else
                    {{ $post->published_at->diffForHumans() }}
                @endif
                @if ($post->wasEdited())
                    &middot; {{ __('openbook.posts.edited') }}
                @endif
            </div>
        </div>
    </div>

    @if ($isDeleted)
        <p class="ob-post__deleted">{{ __('openbook.posts.deleted') }}</p>
    @else
        @if ($post->title)
            <h2 class="ob-post__title">{{ $post->title }}</h2>
        @endif

        @if ($post->hasContentWarning())
            <details class="ob-post__cw">
                <summary>{{ __('openbook.posts.content_warning_label') }}: {{ $post->content_warning }}</summary>
                <div class="ob-post__body">{{ \App\Domain\Posts\PostBodyRenderer::render($post->body) }}</div>
            </details>
        @else
            <div class="ob-post__body">{{ \App\Domain\Posts\PostBodyRenderer::render($post->body) }}</div>
        @endif

        @if ($post->media->isNotEmpty())
            <div class="ob-post__media">
                @foreach ($post->media as $media)
                    <img src="{{ $media->url() }}" alt="{{ $media->alt_text }}" loading="lazy">
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

        <div class="ob-post__actions">
            @auth
                @if ($post->liked_by_viewer)
                    <form method="POST" action="{{ route('posts.unlike', $post) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="ob-btn ob-btn--ghost ob-btn--active"><x-icon name="heart" /> {{ __('openbook.actions.liked', ['count' => $post->likes_count]) }}</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('posts.like', $post) }}">
                        @csrf
                        <button type="submit" class="ob-btn ob-btn--ghost"><x-icon name="heart" /> {{ __('openbook.actions.like', ['count' => $post->likes_count]) }}</button>
                    </form>
                @endif

                <a href="{{ route('posts.show', $post) }}#commenta" class="ob-btn ob-btn--ghost"><x-icon name="comment" /> {{ __('openbook.actions.comment', ['count' => $post->comments_count]) }}</a>

                @if ($post->announced_by_viewer)
                    <form method="POST" action="{{ route('posts.unannounce', $post) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="ob-btn ob-btn--ghost ob-btn--active"><x-icon name="share" /> {{ __('openbook.actions.announced', ['count' => $post->announces_count]) }}</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('posts.announce', $post) }}">
                        @csrf
                        <button type="submit" class="ob-btn ob-btn--ghost"><x-icon name="share" /> {{ __('openbook.actions.announce', ['count' => $post->announces_count]) }}</button>
                    </form>
                @endif

                @can('delete', $post)
                    <form method="POST" action="{{ route('posts.destroy', $post) }}" onsubmit="return confirm('{{ __('openbook.posts.confirm_delete') }}')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="ob-btn ob-btn--ghost"><x-icon name="trash" /> {{ __('openbook.actions.delete') }}</button>
                    </form>
                @endcan
            @else
                <span class="ob-btn ob-btn--ghost" style="cursor:default"><x-icon name="heart" /> {{ __('openbook.actions.like', ['count' => $post->likes_count]) }}</span>
                <a href="{{ route('posts.show', $post) }}#commenti" class="ob-btn ob-btn--ghost"><x-icon name="comment" /> {{ __('openbook.actions.comment', ['count' => $post->comments_count]) }}</a>
                <span class="ob-btn ob-btn--ghost" style="cursor:default"><x-icon name="share" /> {{ __('openbook.actions.announce', ['count' => $post->announces_count]) }}</span>
            @endauth
        </div>
    @endif
</article>
