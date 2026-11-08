@extends('layouts.app')

@section('title', __('openbook.comments.edit_title').' - '.config('app.name'))

@section('content')
    <p><a href="{{ route('comments.show', $comment) }}">{{ __('openbook.comments.cancel_edit') }}</a></p>
    <h1>{{ __('openbook.comments.edit_title') }}</h1>
    @include('composer.form', [
        'mode' => 'comment',
        'action' => route('comments.update', $comment),
        'method' => 'PUT',
        'editingComment' => $comment,
        'autofocus' => true,
    ])
    @if ($comment->media->isNotEmpty())
        <div class="ob-post__media ob-comment__media" data-lightbox-group>
            @foreach ($comment->media as $media)
                @include('media._attachment', ['media' => $media])
            @endforeach
        </div>
    @endif
@endsection
