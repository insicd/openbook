@extends('layouts.app')

@php
    $quotedPost = $quotedPost ?? null;
    $quoteQuery = $quotedPost !== null ? ['quote' => $quotedPost->id] : [];
@endphp

@section('title', __('openbook.messages.title').' - '.config('app.name'))

@section('content')
    <div class="ob-card ob-message-new{{ $quotedPost ? ' ob-message-new--quoting' : '' }}">
        <h2 class="ob-message-new__title">{{ $quotedPost ? __('openbook.messages.share_title') : __('openbook.messages.new_title') }}</h2>
        @if ($quotedPost)
            <p class="ob-message-new__intro">{{ __('openbook.messages.share_intro') }}</p>
            <div class="ob-composer__quote-banner">
                <x-icon name="quote" />
                <span>{{ __('openbook.composer.quoting', ['name' => $quotedPost->actor?->displayName() ?: $quotedPost->actor?->handle()]) }}</span>
                <a href="{{ route('messages.index') }}" class="ob-composer__quote-cancel">{{ __('openbook.composer.quote_cancel') }}</a>
            </div>
            @include('messages._quote', ['quotedPost' => $quotedPost])
        @endif
        <form method="POST" action="{{ route('messages.start') }}" class="ob-message-new__form" id="ob-message-new-form">
            @csrf
            @if ($quotedPost)
                <input type="hidden" name="quote" value="{{ $quotedPost->id }}">
            @endif
            <div class="ob-field ob-message-new__field">
                <label for="ob-message-recipient">{{ __('openbook.messages.recipient_label') }}</label>
                <input type="text" id="ob-message-recipient" name="recipient" maxlength="255" required
                    autocomplete="off"
                    value="{{ old('recipient') }}"
                    placeholder="{{ __('openbook.messages.recipient_placeholder') }}"
                    data-suggest-url="{{ route('messages.suggest_recipients', $quoteQuery) }}"
                    data-suggest-label="{{ __('openbook.messages.recipient_suggest_label') }}"
                    data-suggest-empty="{{ __('openbook.messages.recipient_suggest_empty') }}">
                @error('recipient')
                    <p class="ob-field__error">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit" class="ob-btn ob-btn--primary ob-btn--small">{{ __('openbook.messages.open_chat') }}</button>
        </form>
    </div>

    <div class="ob-card">
        <h1>{{ __('openbook.messages.title') }}</h1>

        @forelse ($conversations as $conversation)
            @php
                $other = $conversation->otherParticipant($viewer);
                $preview = $previews[$conversation->id] ?? null;
                $isUnread = $unreadFlags[$conversation->id] ?? false;
            @endphp
            <a href="{{ route('messages.show', ['conversation' => $conversation] + $quoteQuery) }}"
                class="ob-message-row {{ $isUnread ? 'ob-message-row--unread' : '' }}">
                <x-avatar :actor="$other" style="width:48px;height:48px" />
                <div class="ob-message-row__body">
                    <div class="ob-message-row__top">
                        <strong>{{ $other->displayName() }}</strong>
                        @if ($preview)
                            <span class="ob-message-row__time">{{ $preview->published_at->diffForHumans() }}</span>
                        @endif
                    </div>
                    @if ($preview)
                        <div class="ob-message-row__preview">
                            @if ($preview->actor_id === $viewer->id)
                                <span class="ob-message-row__you">{{ __('openbook.messages.you_prefix') }}</span>
                            @endif
                            @if (filled($preview->body))
                                {{ Str::limit(strip_tags((string) \App\Domain\Posts\PostBodyRenderer::render($preview->body)), 120) }}
                            @elseif ($preview->quoted_post_id)
                                {{ __('openbook.messages.shared_post_preview') }}
                            @endif
                        </div>
                    @endif
                </div>
            </a>
        @empty
            <div class="ob-empty-state">
                <p>{{ __('openbook.messages.empty') }}</p>
            </div>
        @endforelse

        @if ($conversations->hasPages())
            <div class="ob-pagination">
                {{ $conversations->onEachSide(1)->links() }}
            </div>
        @endif
    </div>

    <script src="{{ \App\Support\Assets::url('assets/js/message-recipient-suggest.js') }}" defer></script>
@endsection
