@extends('layouts.app')

@php
    $displayName = $other->displayNameForText();
    $lastMessage = $messages->last();
    $quotedPost = $quotedPost ?? null;
    $quotedActor = $quotedActor ?? null;
    $quotedEvent = $quotedEvent ?? null;
    $isSharing = $quotedPost !== null || $quotedActor !== null || $quotedEvent !== null;
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
                    <strong>{!! $other->displayNameHtml() !!}</strong>
                    <div class="ob-message-thread__handle">{{ '@'.$other->handle() }}</div>
                </div>
            </div>
            <a href="{{ $other->profileUrl() }}" class="ob-btn ob-btn--ghost">
                {{ __('openbook.messages.view_profile') }}
            </a>
        </header>

        <div class="ob-message-thread__messages" id="ob-message-thread"
            data-feed-url="{{ route('messages.feed', $conversation) }}"
            data-last-message-id="{{ $lastMessage?->id }}"
            data-poll-ms="5000"
            data-empty-label="{{ __('openbook.messages.thread_empty') }}">
            @forelse ($messages as $message)
                @include('messages._bubble', ['message' => $message, 'viewer' => $viewer])
            @empty
                <div class="ob-empty-state" data-message-empty>
                    <p>{{ __('openbook.messages.thread_empty') }}</p>
                </div>
            @endforelse
        </div>

        @if ($canSend)
            <form method="POST" action="{{ route('messages.store', $conversation) }}" class="ob-message-composer"
                id="ob-message-composer" data-ajax="1">
                @csrf
                @if ($quotedPost)
                    <input type="hidden" name="quoted_post_id" value="{{ $quotedPost->id }}" data-message-quoted-id>
                    <div class="ob-composer__quote-banner" data-message-quote>
                        <x-icon name="quote" />
                        <span>{!! \App\Domain\Posts\PostBodyRenderer::renderInlineCustomEmojis(
                            __('openbook.composer.quoting', ['name' => $quotedPost->actor?->displayName() ?: $quotedPost->actor?->handle()]),
                            $quotedPost->actor?->custom_emojis,
                        ) !!}</span>
                        <a href="{{ route('messages.show', $conversation) }}" class="ob-composer__quote-cancel">{{ __('openbook.composer.quote_cancel') }}</a>
                    </div>
                    <div data-message-quote-embed>
                        @include('messages._quote', ['quotedPost' => $quotedPost])
                    </div>
                @elseif ($quotedActor)
                    <input type="hidden" name="quoted_actor_id" value="{{ $quotedActor->id }}" data-message-quoted-id>
                    <div class="ob-composer__quote-banner" data-message-quote>
                        <x-icon name="share" />
                        <span>{!! \App\Domain\Posts\PostBodyRenderer::renderInlineCustomEmojis(
                            __('openbook.messages.sharing_profile', ['name' => $quotedActor->displayName()]),
                            $quotedActor->custom_emojis,
                        ) !!}</span>
                        <a href="{{ route('messages.show', $conversation) }}" class="ob-composer__quote-cancel">{{ __('openbook.composer.quote_cancel') }}</a>
                    </div>
                    <div data-message-quote-embed>
                        @include('messages._profile', ['quotedActor' => $quotedActor, 'showFollow' => false])
                    </div>
                @elseif ($quotedEvent)
                    <input type="hidden" name="quoted_event_id" value="{{ $quotedEvent->id }}" data-message-quoted-id>
                    <div class="ob-composer__quote-banner" data-message-quote>
                        <x-icon name="calendar" />
                        <span>{{ __('openbook.messages.sharing_event', ['name' => $quotedEvent->name]) }}</span>
                        <a href="{{ route('messages.show', $conversation) }}" class="ob-composer__quote-cancel">{{ __('openbook.composer.quote_cancel') }}</a>
                    </div>
                    <div data-message-quote-embed>
                        @include('messages._event', ['quotedEvent' => $quotedEvent])
                    </div>
                @endif
                <label class="ob-sr-only" for="message-body">{{ __('openbook.messages.compose_label') }}</label>
                <textarea id="message-body" name="body" rows="3" maxlength="5000"
                    @if (! $isSharing) required @endif
                    data-default-placeholder="{{ __('openbook.messages.compose_placeholder', ['name' => $displayName]) }}"
                    placeholder="{{ $quotedActor
                        ? __('openbook.messages.share_placeholder')
                        : ($quotedEvent
                            ? __('openbook.messages.share_event_placeholder')
                            : ($quotedPost
                            ? __('openbook.messages.quote_placeholder')
                            : __('openbook.messages.compose_placeholder', ['name' => $displayName]))) }}">{{ old('body') }}</textarea>
                <p class="ob-field__error" id="ob-message-error" hidden></p>
                @error('quoted_post_id')
                    <p class="ob-field__error">{{ $message }}</p>
                @enderror
                @error('quoted_actor_id')
                    <p class="ob-field__error">{{ $message }}</p>
                @enderror
                @error('quoted_event_id')
                    <p class="ob-field__error">{{ $message }}</p>
                @enderror
                <div class="ob-message-composer__actions">
                    <button type="submit" class="ob-btn ob-btn--primary" id="ob-message-submit">{{ __('openbook.messages.send') }}</button>
                </div>
            </form>
        @else
            <p class="ob-field__help ob-message-thread__blocked">{{ __('openbook.messages.cannot_send') }}</p>
        @endif
    </div>

    <div id="ob-message-i18n" hidden
        data-send-error="{{ __('openbook.messages.send_error') }}"></div>
    <script src="{{ \App\Support\Assets::url('assets/js/messages-live.js') }}" defer></script>
@endsection
