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
        </div>

        <div class="ob-comment__body">{{ \App\Domain\Posts\PostBodyRenderer::render($comment->body, $comment->custom_emojis, $mentionDomain) }}</div>

        @if ($comment->media->isNotEmpty())
            <div class="ob-post__media ob-comment__media" data-lightbox-group>
                @foreach ($comment->media as $media)
                    @include('media._attachment', ['media' => $media])
                @endforeach
            </div>
        @endif
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
