@extends('layouts.app')

@section('title', __('openbook.messages.title').' - '.config('app.name'))

@section('content')
    <div class="ob-card">
        <h1>{{ __('openbook.messages.title') }}</h1>

        @forelse ($conversations as $conversation)
            @php
                $other = $conversation->otherParticipant($viewer);
                $preview = $previews[$conversation->id] ?? null;
                $isUnread = $unreadFlags[$conversation->id] ?? false;
            @endphp
            <a href="{{ route('messages.show', $conversation) }}"
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
                            {{ Str::limit(strip_tags((string) \App\Domain\Posts\PostBodyRenderer::render($preview->body)), 120) }}
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
@endsection
