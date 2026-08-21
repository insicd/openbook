@extends('layouts.admin')

@section('title', __('openbook.admin.updates.title').' - '.config('app.name'))

@section('content')
    <h1>{{ __('openbook.admin.updates.title') }}</h1>
    <p class="ob-field__help">{{ __('openbook.admin.updates.intro') }}</p>

    <div class="ob-card" style="margin-top:1rem">
        <p><strong>{{ __('openbook.admin.updates.current') }}:</strong> {{ config('openbook.release_label') }}</p>
        <p class="ob-field__help">{{ __('openbook.admin.updates.manifest_source') }}: <code>{{ $manifestUrl }}</code></p>

        @unless ($zipAvailable)
            <div class="ob-alert ob-alert--error" role="alert" style="margin-top:1rem">
                {{ __('openbook.admin.updates.zip_missing') }}
            </div>
        @endunless

        @if ($fetchError)
            <div class="ob-alert ob-alert--error" role="alert" style="margin-top:1rem">
                {{ __('openbook.admin.updates.fetch_error') }}: {{ $fetchError }}
            </div>
            <p class="ob-field__help">{{ __('openbook.admin.updates.fetch_help') }}</p>
        @elseif ($manifest)
            <p style="margin-top:1rem">
                <strong>{{ __('openbook.admin.updates.latest') }}:</strong> {{ $manifest['version'] }}
                @if (! empty($manifest['released_at']))
                    <span class="ob-field__help">({{ $manifest['released_at'] }})</span>
                @endif
            </p>

            @if (! empty($manifest['notes']))
                <p class="ob-field__help">{{ $manifest['notes'] }}</p>
            @endif

            @if (! empty($manifest['changelog_url']))
                <p><a href="{{ $manifest['changelog_url'] }}" target="_blank" rel="noopener noreferrer">{{ __('openbook.admin.updates.changelog') }}</a></p>
            @endif

            @if ($updateAvailable && $zipAvailable)
                <form method="POST" action="{{ route('admin.updates.apply') }}" style="margin-top:1.25rem" onsubmit="return confirm(@json(__('openbook.admin.updates.confirm_prompt')))">
                    @csrf
                    <label style="display:flex;align-items:flex-start;gap:0.5rem;margin-bottom:1rem">
                        <input type="checkbox" name="confirm" value="1" required>
                        <span>{{ __('openbook.admin.updates.confirm_label') }}</span>
                    </label>
                    <button type="submit" class="ob-btn ob-btn--primary">{{ __('openbook.admin.updates.apply_button', ['version' => $manifest['version']]) }}</button>
                </form>
            @elseif (! $updateAvailable)
                <p class="ob-alert ob-alert--success" role="status" style="margin-top:1rem">{{ __('openbook.admin.updates.already_latest') }}</p>
            @endif
        @endif
    </div>
@endsection
