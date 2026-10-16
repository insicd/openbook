@props(['event', 'date', 'showTimezone' => false])
@inject('dates', 'App\Support\UserDate')
@php
    $eventDate = $dates->local($date, $event->timezoneForDisplay());
    $localDate = $dates->local($date);
    $differentTime = $eventDate->getOffset() !== $localDate->getOffset();
    $localFormat = $eventDate->toDateString() === $localDate->toDateString() ? 'H:i' : 'j F Y, H:i';
@endphp
<time datetime="{{ $date->format('c') }}">{{ $eventDate->translatedFormat('j F Y, H:i') }}</time>
@if ($showTimezone && $differentTime)
    <span class="ob-event-timezone">({{ $event->timezoneLabel() }})</span>
@endif
@if ($differentTime)
    <small class="ob-event-local-time">{{ __('openbook.events.your_time', ['date' => $localDate->translatedFormat($localFormat)]) }}</small>
@endif
