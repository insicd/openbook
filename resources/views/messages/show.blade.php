@extends('layouts.app')

@php
    $displayName = $other->displayName();
@endphp

@section('title', $displayName.' - '.__('openbook.messages.title').' - '.config('app.name'))

@section('content')
    <div class="ob-card ob-message-thread">
        <header class="ob-message-thread__header">
            <a href="{{ route('messages.index') }}" class="ob-btn ob-btn--ghost ob-message-thread__back">
                &larr; {{ __('openbook.messages.back') }}
            </a>
            <div class="ob-message-thread__peer">
                <x-avatar :actor="$other" style="width:40px;height:40px" />
                <div>
                    <strong>{{ $displayName }}</strong>
                    <div class="ob-message-thread__handle">{{ '@'.$other->handle() }}</div>
                </div>
            </div>
            <a href="{{ $other->profileUrl() }}" class="ob-btn ob-btn--ghost">
                {{ __('openbook.messages.view_profile') }}
            </a>
        </header>

        <div class="ob-message-thread__messages" id="ob-message-thread">
            @forelse ($messages as $message)
                @php $isMine = $message->actor_id === $viewer->id; @endphp
                <article class="ob-message-bubble {{ $isMine ? 'ob-message-bubble--mine' : 'ob-message-bubble--theirs' }}">
                    <div class="ob-message-bubble__meta">
                        <span>{{ $message->actor->displayName() }}</span>
                        <time datetime="{{ $message->published_at->toIso8601String() }}">
                            {{ $message->published_at->format('d/m/Y H:i') }}
                        </time>
                    </div>
                    <div class="ob-message-bubble__body">
                        {!! \App\Domain\Posts\PostBodyRenderer::render($message->body) !!}
                    </div>
                </article>
            @empty
                <div class="ob-empty-state">
                    <p>{{ __('openbook.messages.thread_empty') }}</p>
                </div>
            @endforelse
        </div>

        @if ($canSend)
            <form method="POST" action="{{ route('messages.store', $conversation) }}" class="ob-message-composer">
                @csrf
                <label class="ob-sr-only" for="message-body">{{ __('openbook.messages.compose_label') }}</label>
                <textarea id="message-body" name="body" rows="3" maxlength="5000" required
                    placeholder="{{ __('openbook.messages.compose_placeholder', ['name' => $displayName]) }}">{{ old('body') }}</textarea>
                @error('body')
                    <p class="ob-field__error">{{ $message }}</p>
                @enderror
                <div class="ob-message-composer__actions">
                    <button type="submit" class="ob-btn ob-btn--primary">{{ __('openbook.messages.send') }}</button>
                </div>
            </form>
        @else
            <p class="ob-field__help ob-message-thread__blocked">{{ __('openbook.messages.cannot_send') }}</p>
        @endif
    </div>

    <script>
        (function () {
            var thread = document.getElementById('ob-message-thread');
            if (thread) {
                thread.scrollTop = thread.scrollHeight;
            }
        })();
    </script>
@endsection
