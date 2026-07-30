@extends('layouts.admin')

@section('title', __('openbook.admin.domain_blocks.title').' - '.config('app.name'))

@section('content')
    <h1>{{ __('openbook.admin.domain_blocks.title') }}</h1>
    <p class="ob-field__help">{{ __('openbook.admin.domain_blocks.intro') }}</p>

    <form method="POST" action="{{ route('admin.domain_blocks.store') }}" class="ob-card" style="margin-top:1rem">
        @csrf
        <div class="ob-field">
            <label for="domain">{{ __('openbook.admin.domain_blocks.domain') }}</label>
            <input type="text" id="domain" name="domain" value="{{ old('domain') }}" required maxlength="255" placeholder="esempio.social">
        </div>
        <div class="ob-field" style="margin-top:1rem">
            <label for="reason">{{ __('openbook.admin.domain_blocks.reason') }}</label>
            <input type="text" id="reason" name="reason" value="{{ old('reason') }}" maxlength="500">
        </div>
        <button type="submit" class="ob-btn ob-btn--primary" style="margin-top:1rem">{{ __('openbook.admin.domain_blocks.block') }}</button>
    </form>

    @forelse ($blocks as $block)
        <article class="ob-card" style="margin-top:1rem">
            <div class="ob-admin-row">
                <div>
                    <strong>{{ $block->domain }}</strong>
                    @if ($block->reason)
                        <p class="ob-field__help" style="margin:0.35rem 0 0">{{ $block->reason }}</p>
                    @endif
                    <p class="ob-field__help" style="margin:0.35rem 0 0">
                        {{ $block->creator?->username ?? '—' }} &middot; {{ $block->created_at?->format('Y-m-d H:i') }}
                    </p>
                </div>
                <form method="POST" action="{{ route('admin.domain_blocks.destroy', $block) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="ob-btn ob-btn--ghost">{{ __('openbook.admin.domain_blocks.unblock') }}</button>
                </form>
            </div>
        </article>
    @empty
        <div class="ob-empty-state" style="margin-top:1.5rem">
            <p>{{ __('openbook.admin.domain_blocks.empty') }}</p>
        </div>
    @endforelse

    <div style="margin-top:1.5rem">{{ $blocks->links() }}</div>
@endsection
