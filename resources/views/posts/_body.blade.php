@php
    /** @var string $body */
    $truncateBody = $truncateBody ?? false;
    $excerptLength = (int) config('openbook.feed.body_excerpt_length', 150);
    $needsExcerpt = $truncateBody && mb_strlen($body) > $excerptLength;
@endphp

@if ($needsExcerpt)
    <details class="ob-post__excerpt">
        <summary class="ob-post__excerpt-summary">
            <div class="ob-post__body">
                {{ \App\Domain\Posts\PostBodyRenderer::render(mb_substr($body, 0, $excerptLength)) }}
                <span class="ob-post__more">{{ __('openbook.posts.read_more') }}</span>
            </div>
        </summary>
        <div class="ob-post__body">{{ \App\Domain\Posts\PostBodyRenderer::render($body) }}</div>
    </details>
@else
    <div class="ob-post__body">{{ \App\Domain\Posts\PostBodyRenderer::render($body) }}</div>
@endif
