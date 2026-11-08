@php
    $values = array_column($points, 'count');
    $maximum = max(1, max($values));
    $coordinates = [];
    foreach ($values as $index => $value) {
        $coordinates[] = round(5 + $index * 90 / max(1, count($values) - 1), 2).','.round(30 - $value * 24 / $maximum, 2);
    }
    $line = implode(' ', $coordinates);
    [$lastX, $lastY] = explode(',', end($coordinates));
    $gradientId = 'hashtag-ticker-'.$tagId;
@endphp
<svg class="ob-hashtag-sparkline" viewBox="0 0 100 36" role="img" aria-label="{{ __('openbook.hashtags.ticker_label') }}">
    <defs>
        <linearGradient id="{{ $gradientId }}" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="currentColor" stop-opacity="0.19" />
            <stop offset="100%" stop-color="currentColor" stop-opacity="0" />
        </linearGradient>
    </defs>
    <polygon points="5,32 {{ $line }} 95,32" fill="url(#{{ $gradientId }})" />
    <polyline points="{{ $line }}" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" />
    <circle cx="{{ $lastX }}" cy="{{ $lastY }}" r="2.3" fill="currentColor" />
</svg>
