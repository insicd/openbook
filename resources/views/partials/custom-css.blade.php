@php
    $isCssPreview = $isCssPreview ?? false;
    $css = $isCssPreview
        ? (string) ($previewCss ?? '')
        : app(\App\Application\Services\InstanceSettings::class)->customCss();
@endphp
@if ($isCssPreview || $css !== '')
    <style id="ob-custom-css">{!! $css !!}</style>
@endif
