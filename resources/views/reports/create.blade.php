@extends('layouts.app')

@php
    $displayName = $post->actor?->displayName() ?: __('openbook.notifications.someone');
@endphp

@section('title', __('openbook.reports.page_title').' - '.config('app.name'))

@section('content')
    <div class="ob-card ob-narrow">
        <h1>{{ __('openbook.reports.page_title') }}</h1>
        <p class="ob-field__help">{{ __('openbook.reports.intro', ['name' => $displayName]) }}</p>

        <div class="ob-report-preview">
            @include('posts._card', [
                'post' => $post,
                'embed' => true,
                'embedDepth' => 1,
                'linkToPost' => true,
            ])
        </div>

        <form method="POST" action="{{ route('posts.report.store', $post) }}" class="ob-stack" style="margin-top:1.25rem">
            @csrf

            <div class="ob-field">
                <label for="report-reason">{{ __('openbook.reports.reason_label') }}</label>
                <select id="report-reason" name="reason" required>
                    <option value="" disabled @selected(! old('reason'))>{{ __('openbook.reports.reason_placeholder') }}</option>
                    @foreach (\App\Domain\Moderation\Report::reasons() as $reason)
                        <option value="{{ $reason }}" @selected(old('reason') === $reason)>
                            {{ __('openbook.reports.reasons.'.$reason) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="ob-field">
                <label for="report-details">{{ __('openbook.reports.details_label') }}</label>
                <textarea id="report-details" name="details" rows="4" maxlength="1000" placeholder="{{ __('openbook.reports.details_placeholder') }}">{{ old('details') }}</textarea>
                <p class="ob-field__help">{{ __('openbook.reports.details_help') }}</p>
            </div>

            <div style="display:flex;gap:0.75rem;flex-wrap:wrap">
                <button type="submit" class="ob-btn ob-btn--primary">{{ __('openbook.reports.submit') }}</button>
                <a href="{{ route('posts.show', $post) }}" class="ob-btn ob-btn--ghost">{{ __('openbook.reports.cancel') }}</a>
            </div>
        </form>
    </div>
@endsection
