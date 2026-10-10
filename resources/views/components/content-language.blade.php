@props(['language' => null])

@php
    $languageLabel = filled($language)
        ? \App\Support\PostLanguageLabel::name($language, app()->getLocale())
        : null;
@endphp

@if ($languageLabel !== null)
    <span aria-hidden="true">&middot;</span>
    <span class="ob-post__language" title="{{ __('openbook.posts.declared_language') }}: {{ $languageLabel }}">
        <span class="sr-only">{{ __('openbook.posts.declared_language') }}: </span><bdi>{{ $languageLabel }}</bdi>
    </span>
@endif
