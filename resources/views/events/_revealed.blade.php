@if ($event->media->isNotEmpty())
    <div class="ob-event-detail__media">
        @foreach ($event->media as $media)
            <img src="{{ $media->displayUrl() }}" alt="{{ $media->alt_text ?: $event->name }}" loading="{{ $loop->first ? 'eager' : 'lazy' }}">
        @endforeach
    </div>
@endif

@if ($distinctSummary)
    <div class="ob-event-detail__summary">{{ \App\Domain\Posts\PostBodyRenderer::render($distinctSummary, $event->custom_emojis, $mentionDomain) }}</div>
@endif
@if ($event->content)
    <div class="ob-event-detail__content">{{ \App\Domain\Posts\PostBodyRenderer::render($event->content, $event->custom_emojis, $mentionDomain) }}</div>
@endif
