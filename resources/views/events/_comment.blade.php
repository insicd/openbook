@php
    /** @var \App\Domain\Events\EventComment $comment */
    $comment = $node['comment'];
    $author = $comment->actor;
    $children = $node['children'] ?? [];
    $depth = $depth ?? 0;
    $isDeleted = !$comment->isPublished();
    $parent = $comment->parent;
    $mentionDomain = parse_url($comment->uri, PHP_URL_HOST);
@endphp

@if ($isDeleted)
    {{-- Il testo eliminato non viene mostrato; le risposte restano nel thread. --}}
    @foreach ($children as $child)
        @include('events._comment', ['node' => $child, 'depth' => $depth])
    @endforeach
@else
    <div class="ob-comment" id="commento-evento-{{ $comment->id }}">
        <div class="ob-post__header">
            <x-avatar :actor="$author" style="width:32px;height:32px;font-size:1rem" />
            <div class="ob-post__meta">
                @if ($author)
                    <a href="{{ $author->profileUrl() }}" class="ob-post__author">{!! $author->displayNameHtml() !!}</a>
                    <div class="ob-post__time">{{ $comment->created_at->diffForHumans() }}</div>
                @endif
                @if ($parent?->isPublished())
                    <div class="ob-comment__in-reply">
                        {!! \App\Domain\Posts\PostBodyRenderer::renderInlineCustomEmojis(
                            __('openbook.comments.in_reply_to', ['name' => $parent->actor?->displayName() ?? $parent->actor?->preferred_username]),
                            $parent->actor?->custom_emojis,
                        ) !!}
                    </div>
                @endif
            </div>

            @can('delete', $comment)
                <details class="ob-post__menu">
                    <summary class="ob-icon-btn" aria-label="{{ __('openbook.comments.menu') }}">
                        <x-icon name="more-vertical" />
                    </summary>
                    <div class="ob-post__menu-panel" role="menu">
                        <form method="POST" action="{{ route('event-comments.destroy', $comment) }}" onsubmit="return confirm(@js(__('openbook.comments.confirm_delete')))">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="ob-post__menu-item" role="menuitem">
                                <x-icon name="trash" /> {{ __('openbook.actions.delete') }}
                            </button>
                        </form>
                    </div>
                </details>
            @endcan
        </div>

        <div class="ob-comment__body">{{ \App\Domain\Posts\PostBodyRenderer::render($comment->body, $comment->custom_emojis, $mentionDomain) }}</div>

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
                    action="{{ $comment->liked_by_viewer ? route('event-comments.unlike', $comment) : route('event-comments.like', $comment) }}"
                    data-like-form
                    data-like-action="{{ route('event-comments.like', $comment) }}"
                    data-unlike-action="{{ route('event-comments.unlike', $comment) }}"
                    data-liked="{{ $comment->liked_by_viewer ? '1' : '0' }}"
                    data-label-like="{{ __('openbook.actions.like', ['count' => '__COUNT__']) }}"
                    data-label-liked="{{ __('openbook.actions.liked', ['count' => '__COUNT__']) }}"
                >
                    @csrf
                    @if ($comment->liked_by_viewer) @method('DELETE') @endif
                    <button type="submit" class="ob-post__action{{ $comment->liked_by_viewer ? ' ob-post__action--active' : '' }}"
                        aria-label="{{ __($comment->liked_by_viewer ? 'openbook.actions.liked' : 'openbook.actions.like', ['count' => $comment->likes_count]) }}">
                        <x-icon name="heart" />
                        <span class="ob-post__action-count">{{ $comment->likes_count }}</span>
                    </button>
                </form>
                @if ($event->isOpenForInteractions())
                    <button type="button" class="ob-post__action" aria-label="{{ __('openbook.actions.reply') }}"
                        onclick="(function(){var c=document.getElementById('risposta-evento-{{ $comment->id }}');if(!c)return;c.hidden=false;var t=document.getElementById('risposta-evento-testo-{{ $comment->id }}');if(t)t.focus();}())">
                        <x-icon name="comment" />
                    </button>
                @endif
            @else
                <span class="ob-post__action"><x-icon name="heart" /><span class="ob-post__action-count">{{ $comment->likes_count }}</span></span>
            @endauth
        </div>

        @auth
            @if ($event->isOpenForInteractions())
                <div style="margin-top:0.6rem">
                    @include('composer.form', [
                        'mode' => 'reply',
                        'formId' => 'risposta-evento-'.$comment->id,
                        'bodyId' => 'risposta-evento-testo-'.$comment->id,
                        'prefix' => 'risposta-evento-'.$comment->id,
                        'action' => route('event-comments.store', $event),
                        'parentCommentId' => $comment->id,
                        'formHidden' => true,
                        'showLabel' => true,
                        'replyToName' => $author?->displayNameForText(),
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
                    @include('events._comment', ['node' => $child, 'depth' => 1])
                @endforeach
            </div>
        @else
            @foreach ($children as $child)
                @include('events._comment', ['node' => $child, 'depth' => 1])
            @endforeach
        @endif
    @endif
@endif
