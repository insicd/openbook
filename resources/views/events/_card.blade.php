@php
    $cover = $event->media->first();
    $offset = $event->utc_offset_minutes;
    $timezone = $event->timezone
        ?? ($offset !== null
            ? sprintf('%s%02d:%02d', $offset >= 0 ? '+' : '-', intdiv(abs($offset), 60), abs($offset) % 60)
            : config('app.timezone'));
    $startsAt = $event->start_at->copy()->setTimezone($timezone);
    $location = $event->location;
    $locationLabel = $location?->name ?: $location?->address ?: $location?->locality;
@endphp

<article class="ob-event-card">
    <a href="{{ route('events.show', $event) }}" class="ob-event-card__link">
        <div class="ob-event-card__cover">
            @if ($cover && ! $event->sensitive)
                <img src="{{ $cover->displayUrl() }}" alt="{{ $cover->alt_text ?: $event->name }}" loading="lazy">
            @else
                <div class="ob-event-card__placeholder"><x-icon name="calendar" /></div>
            @endif
        </div>
        <div class="ob-event-card__body">
            @if ($showTypeBadge ?? false)
                <span class="ob-badge">{{ __('openbook.events.badge') }}</span>
            @endif
            <div class="ob-event-card__date">{{ $startsAt->translatedFormat('j F Y, H:i') }}</div>
            <h3>{!! \App\Domain\Posts\PostBodyRenderer::renderInlineCustomEmojis($event->name, $event->custom_emojis) !!}</h3>
            @if ($locationLabel)
                <div class="ob-event-card__location"><x-icon name="map-pin" /> {{ $locationLabel }}</div>
            @elseif ($event->is_online)
                <div class="ob-event-card__location"><x-icon name="globe" /> {{ __('openbook.events.online') }}</div>
            @endif
            @if ($event->status === \App\Domain\Events\Event::STATUS_CANCELLED)
                <span class="ob-badge ob-event-status--cancelled">{{ __('openbook.events.cancelled') }}</span>
            @elseif ($event->status === \App\Domain\Events\Event::STATUS_TENTATIVE)
                <span class="ob-badge">{{ __('openbook.events.tentative') }}</span>
            @elseif ($event->status === \App\Domain\Events\Event::STATUS_POSTPONED)
                <span class="ob-badge">{{ __('openbook.events.postponed') }}</span>
            @endif
        </div>
    </a>
</article>
