@php
    /** @var \App\Application\Queries\ActorActivityItem $item */
    $targetUrl = $item->targetUrl();
    $excerpt = $item->excerpt();
@endphp
<div class="ob-activity" data-activity-type="{{ $item->type }}" data-activity-id="{{ $item->id }}">
    @if ($targetUrl)
        <a href="{{ $targetUrl }}" class="ob-activity__stretch" tabindex="-1" aria-hidden="true"></a>
    @endif

    <span class="ob-activity__icon" aria-hidden="true">
        <x-icon name="{{ $item->iconName() }}" />
    </span>

    <div class="ob-activity__body">
        <div>{!! $item->messageHtml() !!}</div>
        @if ($excerpt)
            <div class="ob-activity__excerpt">{{ $excerpt }}</div>
        @endif
        <div class="ob-activity__time">{{ $item->occurredAt->diffForHumans() }}</div>
    </div>
</div>
