@extends('layouts.app')

@section('title', __('openbook.events.title').' - '.config('app.name'))

@section('content')
    <div class="ob-card">
        <h1>{{ __('openbook.events.title') }}</h1>
        <p class="ob-field__help">{{ __('openbook.events.subtitle') }}</p>
        <div class="ob-scope-switch" role="tablist">
            <a href="{{ route('events.index') }}" class="ob-btn {{ !$archive ? 'ob-btn--primary' : 'ob-btn--ghost' }}" role="tab" aria-selected="{{ !$archive ? 'true' : 'false' }}">
                {{ __('openbook.events.upcoming') }}
            </a>
            <a href="{{ route('events.archive') }}" class="ob-btn {{ $archive ? 'ob-btn--primary' : 'ob-btn--ghost' }}" role="tab" aria-selected="{{ $archive ? 'true' : 'false' }}">
                {{ __('openbook.events.archive') }}
            </a>
        </div>
    </div>

    @if (!$archive && $yourEvents->isNotEmpty())
        <section class="ob-card">
            <h2>{{ __('openbook.events.your_events') }}</h2>
            <p class="ob-field__help">{{ __('openbook.events.your_events_help') }}</p>
            <div class="ob-event-grid">
                @foreach ($yourEvents as $event)
                    @include('events._card', ['event' => $event])
                @endforeach
            </div>
        </section>
    @endif

    <section class="ob-card">
        <h2>{{ $archive ? __('openbook.events.archive') : __('openbook.events.upcoming') }}</h2>
        @if ($events->isEmpty())
            <div class="ob-empty-state"><p>{{ __('openbook.events.empty') }}</p></div>
        @else
            <div
                class="ob-event-grid"
                data-infinite-scroll
                @if ($events->hasMorePages()) data-next-url="{{ $events->nextPageUrl() }}" @endif
                data-loading-label="{{ __('openbook.events.infinite_scroll.loading') }}"
                data-end-label="{{ __('openbook.events.infinite_scroll.end') }}"
                data-error-label="{{ __('openbook.events.infinite_scroll.error') }}"
            >
                @foreach ($events as $event)
                    @include('events._card', ['event' => $event])
                @endforeach
            </div>
            @if ($events->hasMorePages())
                <noscript>
                    <div class="ob-pagination">
                        <a href="{{ $events->nextPageUrl() }}">{{ __('openbook.infinite_scroll.next') }}</a>
                    </div>
                </noscript>
            @endif
        @endif
    </section>
@endsection
