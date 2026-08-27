@php
    /** @var \App\Domain\Comments\Comment $comment */
    $comment = $node['comment'];
    $author = $comment->actor;
    $displayName = $author?->displayName();
    $isDeleted = $comment->status === \App\Domain\Comments\Comment::STATUS_DELETED;
    $children = $node['children'] ?? [];
    $depth = $depth ?? 0;
    $threadParentId = $threadParentId ?? null;
    $focusedId = $focusedId ?? null;
    $showReplyForm = $showReplyForm ?? true;
    $isFocused = $focusedId !== null && $focusedId === $comment->id;
    $parent = $comment->parent;
    $showInReplyTo = $parent !== null
        && $parent->isPublished()
        && $parent->id !== $threadParentId;
@endphp

@if ($isDeleted)
    {{-- Privacy: nessun tombstone in UI. Le eventuali risposte restano visibili. --}}
    @foreach ($children as $child)
        @include('comments._comment', [
            'node' => $child,
            'post' => $post,
            'depth' => $depth,
            'threadParentId' => $threadParentId,
            'focusedId' => $focusedId,
            'showReplyForm' => $showReplyForm,
        ])
    @endforeach
@else
    <div @class(['ob-comment', 'ob-comment--focused' => $isFocused]) id="commento-{{ $comment->id }}">
        <div class="ob-post__header">
            <x-avatar :actor="$author" style="width:32px;height:32px;font-size:1rem" />
            <div class="ob-post__meta">
                @if ($author)
                    <a href="{{ $author->profileUrl() }}" class="ob-post__author">{{ $displayName }}</a>
                    <div class="ob-post__time">
                        <a href="{{ route('comments.show', $comment) }}" class="ob-comment__permalink" aria-label="{{ __('openbook.comments.open_thread') }}">
                            {{ $comment->created_at->diffForHumans() }}
                        </a>
                    </div>
                @endif
                @if ($showInReplyTo)
                    <a href="{{ route('comments.show', $parent) }}" class="ob-comment__in-reply">
                        {{ __('openbook.comments.in_reply_to', ['name' => $parent->actor?->displayName() ?? $parent->actor?->preferred_username]) }}
                    </a>
                @endif
            </div>

            @canany(['delete', 'report'], $comment)
                <details class="ob-post__menu">
                    <summary class="ob-icon-btn" aria-label="{{ __('openbook.comments.menu') }}">
                        <x-icon name="more-vertical" />
                    </summary>
                    <div class="ob-post__menu-panel" role="menu">
                        @can('report', $comment)
                            <a href="{{ route('comments.report.create', $comment) }}" class="ob-post__menu-item" role="menuitem">
                                <x-icon name="flag" />
                                {{ __('openbook.actions.report') }}
                            </a>
                        @endcan
                        @can('delete', $comment)
                            <form method="POST" action="{{ route('comments.destroy', $comment) }}" onsubmit="return confirm('{{ __('openbook.comments.confirm_delete') }}')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="ob-post__menu-item" role="menuitem">
                                    <x-icon name="trash" />
                                    {{ __('openbook.actions.delete') }}
                                </button>
                            </form>
                        @endcan
                    </div>
                </details>
            @endcanany
        </div>

        <div class="ob-comment__body">{{ \App\Domain\Posts\PostBodyRenderer::render($comment->body) }}</div>

        @if ($comment->media->isNotEmpty())
            <div class="ob-post__media ob-comment__media" data-lightbox-group>
                @foreach ($comment->media as $media)
                    @include('media._attachment', ['media' => $media])
                @endforeach
            </div>
        @endif

        <div class="ob-post__actions">
            @auth
                <form
                    method="POST"
                    action="{{ $comment->liked_by_viewer ? route('comments.unlike', $comment) : route('comments.like', $comment) }}"
                    data-like-form
                    data-like-action="{{ route('comments.like', $comment) }}"
                    data-unlike-action="{{ route('comments.unlike', $comment) }}"
                    data-liked="{{ $comment->liked_by_viewer ? '1' : '0' }}"
                    data-label-like="{{ __('openbook.actions.like', ['count' => '__COUNT__']) }}"
                    data-label-liked="{{ __('openbook.actions.liked', ['count' => '__COUNT__']) }}"
                >
                    @csrf
                    @if ($comment->liked_by_viewer)
                        @method('DELETE')
                    @endif
                    <button
                        type="submit"
                        class="ob-post__action{{ $comment->liked_by_viewer ? ' ob-post__action--active' : '' }}"
                        aria-label="{{ $comment->liked_by_viewer ? __('openbook.actions.liked', ['count' => $comment->likes_count]) : __('openbook.actions.like', ['count' => $comment->likes_count]) }}"
                    >
                        <x-icon name="heart" />
                        <span class="ob-post__action-count">{{ $comment->likes_count }}</span>
                    </button>
                </form>

                @if ($showReplyForm)
                    <button
                        type="button"
                        class="ob-post__action"
                        aria-label="{{ __('openbook.actions.reply') }}"
                        onclick="(function(){var c=document.getElementById('risposta-{{ $comment->id }}');if(!c)return;c.hidden=false;var t=document.getElementById('risposta-testo-{{ $comment->id }}');if(t){t.focus();}}())"
                    >
                        <x-icon name="comment" />
                    </button>
                @endif
            @else
                <span class="ob-post__action" aria-label="{{ __('openbook.actions.like', ['count' => $comment->likes_count]) }}">
                    <x-icon name="heart" />
                    <span class="ob-post__action-count">{{ $comment->likes_count }}</span>
                </span>
            @endauth
        </div>

        @auth
            @if ($showReplyForm)
                <div style="margin-top:0.6rem">
                    @include('composer.form', [
                        'mode' => 'reply',
                        'formId' => 'risposta-'.$comment->id,
                        'bodyId' => 'risposta-testo-'.$comment->id,
                        'prefix' => 'risposta-'.$comment->id,
                        'action' => route('comments.store', $post),
                        'parentCommentId' => $comment->id,
                        'formHidden' => true,
                        'showLabel' => true,
                        'replyToName' => $displayName,
                        'rows' => 2,
                    ])
                </div>
            @endif
        @endauth
    </div>

    @if ($children !== [])
        @if ($depth < 1)
            <div class="ob-comment__replies">
                @foreach ($children as $child)
                    @include('comments._comment', [
                        'node' => $child,
                        'post' => $post,
                        'depth' => 1,
                        'threadParentId' => $comment->id,
                        'focusedId' => $focusedId,
                        'showReplyForm' => $showReplyForm,
                    ])
                @endforeach
            </div>
        @else
            @foreach ($children as $child)
                @include('comments._comment', [
                    'node' => $child,
                    'post' => $post,
                    'depth' => 1,
                    'threadParentId' => $threadParentId,
                    'focusedId' => $focusedId,
                    'showReplyForm' => $showReplyForm,
                ])
            @endforeach
        @endif
    @endif
@endif
