@extends('layouts.admin')

@section('title', __('openbook.admin.reports.detail_title').' - '.config('app.name'))

@section('content')
    <p><a href="{{ route('admin.reports.index') }}">&larr; {{ __('openbook.admin.reports.back') }}</a></p>
    <h1>{{ __('openbook.admin.reports.detail_title') }}</h1>

    <div class="ob-card" style="margin-top:1rem">
        <p>
            <span class="ob-badge">{{ __('openbook.admin.reports.status.'.$report->status) }}</span>
            <span class="ob-badge">{{ __('openbook.reports.reasons.'.$report->reason) }}</span>
            <span class="ob-badge">{{ $report->isCommentReport() ? __('openbook.admin.reports.type_comment') : __('openbook.admin.reports.type_post') }}</span>
        </p>
        <p>{{ __('openbook.admin.reports.by', ['name' => $report->reporter?->username ?? '—']) }}
            &middot; {{ $report->created_at->format('Y-m-d H:i') }}</p>
        @if ($report->details)
            <p><strong>{{ __('openbook.reports.details_label') }}:</strong> {{ $report->details }}</p>
        @endif
        @if ($report->reviewer)
            <p class="ob-field__help">{{ __('openbook.admin.reports.reviewed_by', ['name' => $report->reviewer->username, 'date' => $report->reviewed_at?->format('Y-m-d H:i')]) }}</p>
        @endif
    </div>

    @if ($report->post)
        <div class="ob-report-preview" style="margin-top:1rem">
            @include('posts._card', [
                'post' => $report->post,
                'embed' => true,
                'embedDepth' => 1,
                'linkToPost' => true,
            ])
        </div>
        @unless ($report->post->isRemote())
            <p class="ob-field__help"><a href="{{ route('posts.show', $report->post) }}">{{ __('openbook.admin.reports.open_post') }}</a></p>
        @else
            <p class="ob-field__help"><a href="{{ $report->post->uri }}" target="_blank" rel="noopener noreferrer">{{ __('openbook.admin.reports.open_remote') }}</a></p>
        @endunless
    @elseif ($report->comment)
        <div class="ob-card" style="margin-top:1rem;padding:1rem">
            <p class="ob-field__help">{{ $report->comment->actor?->displayName() }}</p>
            @if ($report->comment->status === \App\Domain\Comments\Comment::STATUS_DELETED)
                <p class="ob-post__deleted">{{ __('openbook.comments.deleted') }}</p>
            @else
                <div>{{ \App\Domain\Posts\PostBodyRenderer::render($report->comment->body) }}</div>
            @endif
        </div>
        @if ($report->comment->post)
            <p class="ob-field__help"><a href="{{ route('posts.show', $report->comment->post) }}#commento-{{ $report->comment->id }}">{{ __('openbook.admin.reports.open_comment') }}</a></p>
        @endif
    @else
        <p class="ob-field__help">{{ __('openbook.admin.reports.target_missing') }}</p>
    @endif

    <div class="ob-admin-actions" style="margin-top:1.25rem">
        @if ($report->status === \App\Domain\Moderation\Report::STATUS_OPEN)
            <form method="POST" action="{{ route('admin.reports.review', $report) }}">
                @csrf
                <button type="submit" class="ob-btn ob-btn--ghost">{{ __('openbook.admin.reports.mark_reviewed') }}</button>
            </form>
            <form method="POST" action="{{ route('admin.reports.dismiss', $report) }}">
                @csrf
                <button type="submit" class="ob-btn ob-btn--ghost">{{ __('openbook.admin.reports.dismiss') }}</button>
            </form>
            <form method="POST" action="{{ route('admin.reports.action', $report) }}">
                @csrf
                @if ($report->post && ! $report->post->isRemote() && $report->post->isPublished())
                    <label class="ob-field__help" style="display:flex;align-items:center;gap:0.4rem;margin-bottom:0.5rem">
                        <input type="checkbox" name="delete_post" value="1">
                        {{ __('openbook.admin.reports.delete_local_post') }}
                    </label>
                @endif
                @if ($report->comment && ! $report->comment->isRemote() && $report->comment->status === \App\Domain\Comments\Comment::STATUS_PUBLISHED)
                    <label class="ob-field__help" style="display:flex;align-items:center;gap:0.4rem;margin-bottom:0.5rem">
                        <input type="checkbox" name="delete_comment" value="1">
                        {{ __('openbook.admin.reports.delete_local_comment') }}
                    </label>
                @endif
                <button type="submit" class="ob-btn ob-btn--primary">{{ __('openbook.admin.reports.mark_actioned') }}</button>
            </form>
        @endif
    </div>
@endsection
