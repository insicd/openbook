@props(['via' => null])

@php($sourceLabel = \App\Support\PostReceivedViaLabel::text($via))

@if ($sourceLabel !== null)
    <details class="ob-post__source">
        <summary class="ob-post__source-trigger" aria-label="{{ $sourceLabel }}">
            <x-icon name="route" />
        </summary>
        <div class="ob-post__source-panel" role="note">{{ $sourceLabel }}</div>
    </details>
@endif
