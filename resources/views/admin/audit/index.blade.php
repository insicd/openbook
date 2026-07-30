@extends('layouts.admin')

@section('title', __('openbook.admin.audit.title').' - '.config('app.name'))

@section('content')
    <h1>{{ __('openbook.admin.audit.title') }}</h1>
    <p class="ob-field__help">{{ __('openbook.admin.audit.intro') }}</p>

    @forelse ($logs as $log)
        <article class="ob-card" style="margin-top:0.75rem">
            <p>
                <strong>{{ $log->action }}</strong>
                <span class="ob-field__help">{{ $log->created_at?->format('Y-m-d H:i:s') }}</span>
            </p>
            <p class="ob-field__help">
                {{ $log->actor?->username ?? '—' }}
                @if ($log->subject_type)
                    &middot; {{ $log->subject_type }}#{{ $log->subject_id }}
                @endif
                @if ($log->ip)
                    &middot; {{ $log->ip }}
                @endif
            </p>
            @if ($log->meta)
                <p class="ob-field__help" style="word-break:break-word">{{ json_encode($log->meta, JSON_UNESCAPED_UNICODE) }}</p>
            @endif
        </article>
    @empty
        <div class="ob-empty-state" style="margin-top:1.5rem">
            <p>{{ __('openbook.admin.audit.empty') }}</p>
        </div>
    @endforelse

    <div style="margin-top:1.5rem">{{ $logs->links() }}</div>
@endsection
