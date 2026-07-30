@php
    /** @var \App\Domain\Posts\Post $post */
    $author = $post->actor;
    $displayName = $author?->displayName();
    $handle = $author ? '@'.$author->handle() : '';
    $isDeleted = $post->status === \App\Domain\Posts\Post::STATUS_DELETED;
    $linkToPost = $linkToPost ?? true;
    $embed = $embed ?? false;
    $embedDepth = $embedDepth ?? 0;
    $sharedBy = $embed ? null : ($post->sharedBy ?? null);
    // I post remoti puntano al loro "uri" ActivityPub (di solito anche la
    // pagina HTML originale sull'istanza remota); i locali restano sulla
    // pagina Openbook del post, a meno che non si sia gia' su quella pagina
    // ($linkToPost = false, usato da posts/show).
    $timeHref = $post->isRemote() && filled($post->uri)
        ? $post->uri
        : ($linkToPost ? route('posts.show', $post) : null);
    $timeIsExternal = $post->isRemote() && filled($post->uri);
@endphp

<article class="ob-card ob-post{{ $embed ? ' ob-post--embed' : '' }}">
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
                @if ($timeHref)
                    <a
                        href="{{ $timeHref }}"
                        @if ($timeIsExternal) target="_blank" rel="noopener noreferrer" title="{{ __('openbook.posts.open_original') }}" @endif
                    >{{ $post->published_at->diffForHumans() }}</a>
                @else
                    {{ $post->published_at->diffForHumans() }}
                @endif
                @if ($post->wasEdited())
                    &middot; {{ __('openbook.posts.edited') }}
                @endif
            </div>
        </div>

        @if (! $embed)
            @can('delete', $post)
                <details class="ob-post__menu">
                    <summary class="ob-icon-btn" aria-label="{{ __('openbook.posts.menu') }}">
                        <x-icon name="more-vertical" />
                    </summary>
                    <div class="ob-post__menu-panel" role="menu">
                        <form method="POST" action="{{ route('posts.destroy', $post) }}" onsubmit="return confirm('{{ __('openbook.posts.confirm_delete') }}')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="ob-post__menu-item" role="menuitem">
                                <x-icon name="trash" />
                                {{ __('openbook.actions.delete') }}
                            </button>
                        </form>
                    </div>
                </details>
            @endcan
        @endif
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
                @include('posts._video_embed_if_any', ['body' => $post->body])
            </details>
        @else
            <div class="ob-post__body">{{ \App\Domain\Posts\PostBodyRenderer::render($post->body) }}</div>
            @include('posts._video_embed_if_any', ['body' => $post->body])
        @endif

        @if ($post->media->isNotEmpty())
            <div class="ob-post__media" data-lightbox-group>
                @foreach ($post->media as $media)
                    <img
                        src="{{ $media->thumbnailUrl() }}"
                        data-full-src="{{ $media->url() }}"
                        alt="{{ $media->alt_text }}"
                        loading="lazy"
                        data-lightbox-trigger
                    >
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
                ])
            </div>
        @endif

        @unless ($embed)
            <div class="ob-post__actions">
                @auth
                    @if ($post->liked_by_viewer)
                        <form method="POST" action="{{ route('posts.unlike', $post) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="ob-post__action ob-post__action--active" aria-label="{{ __('openbook.actions.liked', ['count' => $post->likes_count]) }}">
                                <x-icon name="heart" />
                                <span class="ob-post__action-count">{{ $post->likes_count }}</span>
                            </button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('posts.like', $post) }}">
                            @csrf
                            <button type="submit" class="ob-post__action" aria-label="{{ __('openbook.actions.like', ['count' => $post->likes_count]) }}">
                                <x-icon name="heart" />
                                <span class="ob-post__action-count">{{ $post->likes_count }}</span>
                            </button>
                        </form>
                    @endif

                    <a href="{{ route('posts.show', $post) }}#commenta" class="ob-post__action" aria-label="{{ __('openbook.actions.comment', ['count' => $post->comments_count]) }}">
                        <x-icon name="comment" />
                        <span class="ob-post__action-count">{{ $post->comments_count }}</span>
                    </a>

                    <details class="ob-post__share-menu">
                        <summary
                            class="ob-post__action{{ $post->announced_by_viewer ? ' ob-post__action--active' : '' }}"
                            aria-label="{{ $post->announced_by_viewer ? __('openbook.actions.announced', ['count' => $post->announces_count]) : __('openbook.actions.announce', ['count' => $post->announces_count]) }}"
                        >
                            <x-icon name="share" />
                            <span class="ob-post__action-count">{{ $post->announces_count }}</span>
                        </summary>
                        <div class="ob-post__menu-panel" role="menu">
                            @if ($post->announced_by_viewer)
                                <form method="POST" action="{{ route('posts.unannounce', $post) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="ob-post__menu-item" role="menuitem">
                                        <x-icon name="share" />
                                        {{ __('openbook.actions.unannounce') }}
                                    </button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('posts.announce', $post) }}">
                                    @csrf
                                    <button type="submit" class="ob-post__menu-item" role="menuitem">
                                        <x-icon name="share" />
                                        {{ __('openbook.actions.announce_direct') }}
                                    </button>
                                </form>
                            @endif
                            <a href="{{ route('posts.quote', $post) }}" class="ob-post__menu-item" role="menuitem">
                                <x-icon name="quote" />
                                {{ __('openbook.actions.announce_quote') }}
                            </a>
                        </div>
                    </details>
                @else
                    <span class="ob-post__action" aria-label="{{ __('openbook.actions.like', ['count' => $post->likes_count]) }}">
                        <x-icon name="heart" />
                        <span class="ob-post__action-count">{{ $post->likes_count }}</span>
                    </span>
                    <a href="{{ route('posts.show', $post) }}#commenti" class="ob-post__action" aria-label="{{ __('openbook.actions.comment', ['count' => $post->comments_count]) }}">
                        <x-icon name="comment" />
                        <span class="ob-post__action-count">{{ $post->comments_count }}</span>
                    </a>
                    <span class="ob-post__action" aria-label="{{ __('openbook.actions.announce', ['count' => $post->announces_count]) }}">
                        <x-icon name="share" />
                        <span class="ob-post__action-count">{{ $post->announces_count }}</span>
                    </span>
                @endauth
            </div>
        @endunless
    @endif
</article>
