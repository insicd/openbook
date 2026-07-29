@php
    /** @var \App\Domain\Comments\Comment $comment */
    $comment = $node['comment'];
    $author = $comment->actor;
    $displayName = $author?->displayName();
    $isDeleted = $comment->status === \App\Domain\Comments\Comment::STATUS_DELETED;
@endphp

<div class="ob-comment" id="commento-{{ $comment->id }}">
    <div class="ob-post__header">
        <x-avatar :actor="$author" style="width:32px;height:32px;font-size:1rem" />
        <div class="ob-post__meta">
            @if ($author)
                <a href="{{ $author->profileUrl() }}" class="ob-post__author">{{ $displayName }}</a>
                <div class="ob-post__time">{{ $comment->created_at->diffForHumans() }}</div>
            @endif
        </div>

        @can('delete', $comment)
            <details class="ob-post__menu">
                <summary class="ob-icon-btn" aria-label="{{ __('openbook.comments.menu') }}">
                    <x-icon name="more-vertical" />
                </summary>
                <div class="ob-post__menu-panel" role="menu">
                    <form method="POST" action="{{ route('comments.destroy', $comment) }}" onsubmit="return confirm('{{ __('openbook.comments.confirm_delete') }}')">
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
    </div>

    @if ($isDeleted)
        <p class="ob-post__deleted">{{ __('openbook.comments.deleted') }}</p>
    @else
        <div class="ob-comment__body">{{ \App\Domain\Posts\PostBodyRenderer::render($comment->body) }}</div>

        <div class="ob-post__actions">
            @auth
                @if ($comment->liked_by_viewer)
                    <form method="POST" action="{{ route('comments.unlike', $comment) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="ob-post__action ob-post__action--active" aria-label="{{ __('openbook.actions.liked', ['count' => $comment->likes_count]) }}">
                            <x-icon name="heart" />
                            <span class="ob-post__action-count">{{ $comment->likes_count }}</span>
                        </button>
                    </form>
                @else
                    <form method="POST" action="{{ route('comments.like', $comment) }}">
                        @csrf
                        <button type="submit" class="ob-post__action" aria-label="{{ __('openbook.actions.like', ['count' => $comment->likes_count]) }}">
                            <x-icon name="heart" />
                            <span class="ob-post__action-count">{{ $comment->likes_count }}</span>
                        </button>
                    </form>
                @endif

                <button
                    type="button"
                    class="ob-post__action"
                    aria-label="{{ __('openbook.actions.reply') }}"
                    onclick="document.getElementById('risposta-{{ $comment->id }}').hidden = false"
                >
                    <x-icon name="comment" />
                </button>
            @else
                <span class="ob-post__action" aria-label="{{ __('openbook.actions.like', ['count' => $comment->likes_count]) }}">
                    <x-icon name="heart" />
                    <span class="ob-post__action-count">{{ $comment->likes_count }}</span>
                </span>
            @endauth
        </div>

        @auth
            <form method="POST" action="{{ route('comments.store', $post) }}" id="risposta-{{ $comment->id }}" hidden style="margin-top:0.6rem">
                @csrf
                <input type="hidden" name="parent_comment_id" value="{{ $comment->id }}">
                <div class="ob-field">
                    <label for="risposta-testo-{{ $comment->id }}" class="ob-field__help">{{ __('openbook.comments.reply_to', ['name' => $displayName]) }}</label>
                    <textarea id="risposta-testo-{{ $comment->id }}" name="body" rows="2" required maxlength="2000"></textarea>
                </div>
                <button type="submit" class="ob-btn ob-btn--primary">{{ __('openbook.actions.reply') }}</button>
            </form>
        @endauth
    @endif

    @if (! empty($node['children']))
        <div class="ob-comment__replies">
            @foreach ($node['children'] as $child)
                @include('comments._comment', ['node' => $child, 'post' => $post])
            @endforeach
        </div>
    @endif
</div>
