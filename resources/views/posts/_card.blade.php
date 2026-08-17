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
    // Orario: sempre la pagina Openbook del post (anche se remoto). L'originale
    // ActivityPub si apre dalla voce di menu "Apri post originale".
    $timeHref = $linkToPost ? route('posts.show', $post) : null;
    $canOpenOriginal = $post->isRemote() && filled($post->uri);
@endphp

<article @if (! $embed) id="post-{{ $post->id }}" @endif class="ob-card ob-post{{ $embed ? ' ob-post--embed' : '' }}">
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
                    <a href="{{ $timeHref }}">{{ $post->published_at->diffForHumans() }}</a>
                @else
                    {{ $post->published_at->diffForHumans() }}
                @endif
                @if ($post->wasEdited())
                    &middot; {{ __('openbook.posts.edited') }}
                @endif
            </div>
        </div>

        @if (! $embed)
            @php
                $canDeletePost = auth()->check() && auth()->user()->can('delete', $post);
                $canReportPost = auth()->check() && auth()->user()->can('report', $post);
                $canFetchUpdates = auth()->check() && $canOpenOriginal;
                $postPermalink = route('posts.show', $post);
            @endphp
            <details class="ob-post__menu">
                <summary class="ob-icon-btn" aria-label="{{ __('openbook.posts.menu') }}">
                    <x-icon name="more-vertical" />
                </summary>
                <div class="ob-post__menu-panel" role="menu">
                    <button
                        type="button"
                        class="ob-post__menu-item"
                        role="menuitem"
                        data-copy-url="{{ $postPermalink }}"
                        data-copy-label="{{ __('openbook.posts.copy_link') }}"
                        data-copy-done="{{ __('openbook.posts.link_copied') }}"
                        data-copy-error="{{ __('openbook.posts.copy_link_error') }}"
                    >
                        <x-icon name="link" />
                        <span data-copy-text>{{ __('openbook.posts.copy_link') }}</span>
                    </button>
                    @if ($canOpenOriginal)
                        <a href="{{ $post->uri }}" class="ob-post__menu-item" role="menuitem" target="_blank" rel="noopener noreferrer">
                            <x-icon name="globe" />
                            {{ __('openbook.posts.open_original') }}
                        </a>
                    @endif
                    @if ($canFetchUpdates)
                        <form method="POST" action="{{ route('posts.fetch_updates', $post) }}">
                            @csrf
                            <button type="submit" class="ob-post__menu-item" role="menuitem">
                                <x-icon name="refresh" />
                                {{ __('openbook.posts.fetch_updates') }}
                            </button>
                        </form>
                    @endif
                    @if ($canReportPost)
                        <a href="{{ route('posts.report.create', $post) }}" class="ob-post__menu-item" role="menuitem">
                            <x-icon name="flag" />
                            {{ __('openbook.actions.report') }}
                        </a>
                    @endif
                    @if ($canDeletePost)
                        <form method="POST" action="{{ route('posts.destroy', $post) }}" onsubmit="return confirm('{{ __('openbook.posts.confirm_delete') }}')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="ob-post__menu-item" role="menuitem">
                                <x-icon name="trash" />
                                {{ __('openbook.actions.delete') }}
                            </button>
                        </form>
                    @endif
                </div>
            </details>
        @endif
    </div>

    @if ($isDeleted)
        <p class="ob-post__deleted">{{ __('openbook.posts.deleted') }}</p>
    @else
        @if ($post->community)
            <p class="ob-post__community">
                <x-icon name="people" />
                <a href="{{ route('communities.show', $post->community) }}">{{ $post->community->actor?->displayName() ?: $post->community->slug }}</a>
            </p>
        @endif
        @if ($post->title)
            <h2 class="ob-post__title">{{ $post->title }}</h2>
        @endif

        @if ($post->hasContentWarning())
            <details class="ob-post__cw">
                <summary>{{ __('openbook.posts.content_warning_label') }}: {{ $post->content_warning }}</summary>
                @include('posts._revealed', [
                    'post' => $post,
                    'embedDepth' => $embedDepth,
                    'truncateBody' => $truncateBody ?? false,
                ])
            </details>
        @else
            @include('posts._revealed', [
                'post' => $post,
                'embedDepth' => $embedDepth,
                'truncateBody' => $truncateBody ?? false,
            ])
        @endif

        @unless ($embed)
            <div class="ob-post__actions">
                @auth
                    <form
                        method="POST"
                        action="{{ $post->liked_by_viewer ? route('posts.unlike', $post) : route('posts.like', $post) }}"
                        data-like-form
                        data-like-action="{{ route('posts.like', $post) }}"
                        data-unlike-action="{{ route('posts.unlike', $post) }}"
                        data-liked="{{ $post->liked_by_viewer ? '1' : '0' }}"
                        data-label-like="{{ __('openbook.actions.like', ['count' => '__COUNT__']) }}"
                        data-label-liked="{{ __('openbook.actions.liked', ['count' => '__COUNT__']) }}"
                    >
                        @csrf
                        @if ($post->liked_by_viewer)
                            @method('DELETE')
                        @endif
                        <button
                            type="submit"
                            class="ob-post__action{{ $post->liked_by_viewer ? ' ob-post__action--active' : '' }}"
                            aria-label="{{ $post->liked_by_viewer ? __('openbook.actions.liked', ['count' => $post->likes_count]) : __('openbook.actions.like', ['count' => $post->likes_count]) }}"
                        >
                            <x-icon name="heart" />
                            <span class="ob-post__action-count">{{ $post->likes_count }}</span>
                        </button>
                    </form>

                    <a href="{{ route('posts.show', $post) }}#commenta" class="ob-post__action" aria-label="{{ __('openbook.actions.comment', ['count' => $post->comments_count]) }}">
                        <x-icon name="comment" />
                        <span class="ob-post__action-count">{{ $post->comments_count }}</span>
                    </a>

                    <details
                        class="ob-post__share-menu"
                        data-announce-menu
                        data-announced="{{ ($post->direct_announced_by_viewer ?? false) ? '1' : '0' }}"
                        data-label-announce="{{ __('openbook.actions.announce', ['count' => '__COUNT__']) }}"
                        data-label-announced="{{ __('openbook.actions.announced', ['count' => '__COUNT__']) }}"
                    >
                        <summary
                            class="ob-post__action{{ ($post->direct_announced_by_viewer ?? false) ? ' ob-post__action--active' : '' }}"
                            data-announce-summary
                            aria-label="{{ ($post->direct_announced_by_viewer ?? false) ? __('openbook.actions.announced', ['count' => $post->announces_count]) : __('openbook.actions.announce', ['count' => $post->announces_count]) }}"
                        >
                            <x-icon name="share" />
                            <span class="ob-post__action-count">{{ $post->announces_count }}</span>
                        </summary>
                        <div class="ob-post__menu-panel" role="menu">
                            <form
                                method="POST"
                                action="{{ route('posts.announce', $post) }}"
                                data-announce-form
                                data-announce-action="{{ route('posts.announce', $post) }}"
                                @class(['ob-share-form--hidden' => ($post->direct_announced_by_viewer ?? false)])
                            >
                                @csrf
                                <button type="submit" class="ob-post__menu-item" role="menuitem">
                                    <x-icon name="share" />
                                    {{ __('openbook.actions.announce_direct') }}
                                </button>
                            </form>
                            <form
                                method="POST"
                                action="{{ route('posts.unannounce', $post) }}"
                                data-announce-form
                                data-unannounce-action="{{ route('posts.unannounce', $post) }}"
                                @class(['ob-share-form--hidden' => ! ($post->direct_announced_by_viewer ?? false)])
                            >
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="ob-post__menu-item" role="menuitem">
                                    <x-icon name="share" />
                                    {{ __('openbook.actions.unannounce') }}
                                </button>
                            </form>
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
