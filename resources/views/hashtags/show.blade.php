@extends('layouts.app')

@section('title', '#'.$tagName.' - '.config('app.name'))

@section('content')
    <div class="ob-card">
        <h1>#{{ $tagName }}</h1>
    </div>

    @forelse ($posts ?? [] as $post)
        @include('posts._card', ['post' => $post])
    @empty
        <div class="ob-card">
            <div class="ob-empty-state">
                <p>{{ __('openbook.hashtags.empty') }}</p>
            </div>
        </div>
    @endforelse

    @if (isset($posts) && $posts->hasPages())
        <div class="ob-pagination">
            {{ $posts->onEachSide(1)->links() }}
        </div>
    @endif
@endsection
