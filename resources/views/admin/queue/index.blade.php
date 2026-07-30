@extends('layouts.admin')

@section('title', __('openbook.admin.queue.title').' - '.config('app.name'))

@section('content')
    <h1>{{ __('openbook.admin.queue.title') }}</h1>
    <p class="ob-field__help">{{ __('openbook.admin.queue.intro') }}</p>

    <div class="ob-admin-stats">
        <div class="ob-card ob-admin-stat">
            <strong>{{ $pendingJobs }}</strong>
            <span>{{ __('openbook.admin.queue.pending_jobs') }}</span>
        </div>
        <div class="ob-card ob-admin-stat">
            <strong>{{ $failedJobs }}</strong>
            <span>{{ __('openbook.admin.queue.failed_jobs') }}</span>
        </div>
        <div class="ob-card ob-admin-stat">
            <strong>{{ $pendingInbox }}</strong>
            <span>{{ __('openbook.admin.queue.pending_inbox') }}</span>
        </div>
        <div class="ob-card ob-admin-stat">
            <strong>{{ $failedInbox }}</strong>
            <span>{{ __('openbook.admin.queue.failed_inbox') }}</span>
        </div>
    </div>

    @if ($failed->isNotEmpty())
        <div class="ob-admin-row" style="margin-top:1.5rem">
            <h2 style="font-size:1.1rem;margin:0">{{ __('openbook.admin.queue.failed_title') }}</h2>
            <form method="POST" action="{{ route('admin.queue.retry_all') }}">
                @csrf
                <button type="submit" class="ob-btn ob-btn--primary">{{ __('openbook.admin.queue.retry_all') }}</button>
            </form>
        </div>

        @foreach ($failed as $job)
            <article class="ob-card" style="margin-top:0.75rem">
                <div class="ob-admin-row">
                    <div>
                        <strong>{{ $job->uuid }}</strong>
                        <p class="ob-field__help" style="margin:0.35rem 0 0">{{ $job->queue }} &middot; {{ $job->failed_at }}</p>
                        <p class="ob-field__help" style="margin:0.35rem 0 0;word-break:break-word">{{ \Illuminate\Support\Str::limit($job->exception, 220) }}</p>
                    </div>
                    <div class="ob-admin-actions">
                        <form method="POST" action="{{ route('admin.queue.retry', $job->uuid) }}">
                            @csrf
                            <button type="submit" class="ob-btn ob-btn--ghost">{{ __('openbook.admin.queue.retry') }}</button>
                        </form>
                        <form method="POST" action="{{ route('admin.queue.forget', $job->uuid) }}">
                            @csrf
                            <button type="submit" class="ob-btn ob-btn--ghost">{{ __('openbook.admin.queue.forget') }}</button>
                        </form>
                    </div>
                </div>
            </article>
        @endforeach
    @endif

    <h2 style="margin-top:1.75rem;font-size:1.1rem">{{ __('openbook.admin.queue.inbox_title') }}</h2>
    @forelse ($inboxItems as $item)
        <article class="ob-card" style="margin-top:0.75rem">
            <p>
                <span class="ob-badge">{{ $item->status }}</span>
                <strong>{{ $item->activity_type }}</strong>
            </p>
            <p class="ob-field__help">{{ $item->actor_uri }} &middot; {{ $item->received_at }}</p>
            @if ($item->error)
                <p class="ob-field__help" style="word-break:break-word">{{ \Illuminate\Support\Str::limit($item->error, 220) }}</p>
            @endif
        </article>
    @empty
        <p class="ob-field__help">{{ __('openbook.admin.queue.inbox_empty') }}</p>
    @endforelse

    <h2 style="margin-top:1.75rem;font-size:1.1rem">{{ __('openbook.admin.queue.pending_title') }}</h2>
    @forelse ($jobs as $job)
        <article class="ob-card" style="margin-top:0.75rem">
            <p><strong>#{{ $job->id }}</strong> {{ $job->queue }}</p>
            <p class="ob-field__help">{{ $job->available_at ? \Illuminate\Support\Carbon::createFromTimestamp($job->available_at)->toDateTimeString() : '—' }}</p>
        </article>
    @empty
        <p class="ob-field__help">{{ __('openbook.admin.queue.pending_empty') }}</p>
    @endforelse
@endsection
