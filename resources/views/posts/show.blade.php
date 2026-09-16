@extends('layouts.app')

@php
    $displayName = $post->actor->displayName();
    $openGraphTitle = $post->title ?: __('openbook.posts.page_title', ['name' => $displayName]);
    $openGraphBodyHtml = (string) \App\Domain\Posts\PostBodyRenderer::render($post->body);
    $openGraphBody = html_entity_decode(
        strip_tags(preg_replace('#<(?:br|/p|/li|/h[1-6])\b[^>]*>#i', ' ', $openGraphBodyHtml) ?? $openGraphBodyHtml),
        ENT_QUOTES | ENT_HTML5,
        'UTF-8',
    );
    $openGraphDescription = \Illuminate\Support\Str::limit(
        trim(preg_replace('/\s+/u', ' ', $openGraphBody) ?? ''),
        200,
    );
    $openGraphMedia = $post->media->first(fn ($media) => str_starts_with($media->mime_type, 'image/'));

    if ($openGraphMedia === null) {
        $openGraphMedia = $post->media->first(
            fn ($media) => $media->isVideo() && $media->thumbnail !== null,
        );
    }

    $openGraphImage = null;
    $openGraphImageType = null;
    $openGraphImageWidth = null;
    $openGraphImageHeight = null;
    $openGraphImageAlt = null;

    if ($openGraphMedia?->isVideo() && $openGraphMedia->thumbnail !== null) {
        $openGraphImage = url($openGraphMedia->thumbnailUrl());
        $openGraphImageType = 'image/jpeg';
        $openGraphImageWidth = $openGraphMedia->thumbnail->width;
        $openGraphImageHeight = $openGraphMedia->thumbnail->height;
        $openGraphImageAlt = $openGraphMedia->alt_text;
    } elseif ($openGraphMedia !== null) {
        $openGraphImage = url($openGraphMedia->url());
        $openGraphImageType = $openGraphMedia->mime_type;
        $openGraphImageWidth = $openGraphMedia->width;
        $openGraphImageHeight = $openGraphMedia->height;
        $openGraphImageAlt = $openGraphMedia->alt_text;
    }
@endphp

@section('title', __('openbook.posts.page_title', ['name' => $displayName]).' - '.config('app.name'))

@if ($post->visibility === \App\Domain\Posts\Post::VISIBILITY_PUBLIC && $post->isPublished())
    @push('head')
        <meta property="og:type" content="article">
        <meta property="og:url" content="{{ route('posts.show', $post) }}">
        <meta property="og:title" content="{{ $openGraphTitle }}">
        @if ($openGraphDescription !== '')
            <meta property="og:description" content="{{ $openGraphDescription }}">
        @endif
        <meta property="og:site_name" content="{{ config('app.name') }}">
        <meta property="og:locale" content="{{ str_replace('-', '_', app()->getLocale()) }}">
        @if ($openGraphImage !== null)
            <meta property="og:image" content="{{ $openGraphImage }}">
            <meta property="og:image:type" content="{{ $openGraphImageType }}">
            @if ($openGraphImageWidth !== null)
                <meta property="og:image:width" content="{{ $openGraphImageWidth }}">
            @endif
            @if ($openGraphImageHeight !== null)
                <meta property="og:image:height" content="{{ $openGraphImageHeight }}">
            @endif
            <meta property="og:image:alt" content="{{ $openGraphImageAlt ?: $openGraphTitle }}">
        @endif
    @endpush
@endif

@section('content')
    @include('posts._card', ['post' => $post, 'linkToPost' => false])

    <div id="commenta">
        @auth
            @include('composer.form', [
                'mode' => 'comment',
                'formId' => null,
                'bodyId' => 'comment-body',
                'prefix' => 'comment',
                'action' => route('comments.store', $post),
                'showLabel' => true,
                'bodyLabel' => __('openbook.comments.new_label'),
                'rows' => 3,
            ])
        @else
            <div class="ob-card">
                <p><a href="{{ route('login') }}">{{ __('openbook.comments.login_to_comment') }}</a></p>
            </div>
        @endauth
    </div>

    <div class="ob-card" id="commenti">
        <h2>{{ __('openbook.comments.title', ['count' => $post->comments_count]) }}</h2>

        @forelse ($commentTree as $node)
            @include('comments._comment', ['node' => $node, 'post' => $post])
        @empty
            <div class="ob-empty-state">
                <p>{{ __('openbook.comments.empty') }}</p>
            </div>
        @endforelse
    </div>
@endsection
