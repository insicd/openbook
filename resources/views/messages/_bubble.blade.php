@php
    $isMine = $message->actor_id === $viewer->id;
@endphp
<article class="ob-message-bubble {{ $isMine ? 'ob-message-bubble--mine' : 'ob-message-bubble--theirs' }}"
    data-message-id="{{ $message->id }}">
    <div class="ob-message-bubble__meta">
        <span>{{ $message->actor->displayName() }}</span>
        <time datetime="{{ $message->published_at->toIso8601String() }}">
            {{ $message->published_at->format('d/m/Y H:i') }}
        </time>
    </div>
    @if (filled($message->body))
        <div class="ob-message-bubble__body">
            {!! \App\Domain\Posts\PostBodyRenderer::render($message->body) !!}
        </div>
    @endif
    @if ($message->quotedPost)
        @include('messages._quote', ['quotedPost' => $message->quotedPost])
    @endif
</article>
