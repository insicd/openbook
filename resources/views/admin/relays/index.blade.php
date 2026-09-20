@extends('layouts.admin')

@section('title', __('openbook.admin.relays.title').' - '.config('app.name'))

@section('content')
    <h1>{{ __('openbook.admin.relays.title') }}</h1>
    <p class="ob-field__help">{{ __('openbook.admin.relays.intro') }}</p>
    <p class="ob-alert" style="margin-top:1rem">{{ __('openbook.admin.relays.traffic_warning') }}</p>

    <div class="ob-card" style="margin-top:1rem">
        <strong>{{ __('openbook.admin.relays.local_actor') }}</strong>
        <p class="ob-field__help" style="margin:0.35rem 0 0">
            <code>{{ $relayActor->handle() }}</code> · <code>{{ $relayActor->activityPubId() }}</code>
        </p>
    </div>

    <form method="POST" action="{{ route('admin.relays.store') }}" class="ob-card" style="margin-top:1rem">
        @csrf
        <div class="ob-field">
            <label for="protocol">{{ __('openbook.admin.relays.protocol') }}</label>
            <select id="protocol" name="protocol" required>
                @foreach ($protocols as $value => $label)
                    <option value="{{ $value }}" @selected(old('protocol', \App\Domain\Federation\Relay::PROTOCOL_MASTODON) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="ob-field" style="margin-top:1rem">
            <label for="inbox_url">{{ __('openbook.admin.relays.relay_address') }}</label>
            <input type="text" id="inbox_url" name="inbox_url" value="{{ old('inbox_url') }}" required maxlength="2048" placeholder="{{ __('openbook.admin.relays.relay_placeholder') }}">
            <p class="ob-field__help">{{ __('openbook.admin.relays.relay_url_help') }}</p>
        </div>
        <div class="ob-field" style="margin-top:1rem">
            <label><input type="checkbox" name="receive_enabled" value="1" @checked(old('receive_enabled', true))> {{ __('openbook.admin.relays.receive_enabled') }}</label>
        </div>
        <div class="ob-field" style="margin-top:0.75rem">
            <label><input type="checkbox" name="publish_enabled" value="1" @checked(old('publish_enabled', true))> {{ __('openbook.admin.relays.publish_enabled') }}</label>
        </div>
        <p class="ob-field__help">{{ __('openbook.admin.relays.inactive_help') }}</p>
        <button type="submit" class="ob-btn ob-btn--primary" style="margin-top:1rem">{{ __('openbook.admin.relays.add') }}</button>
    </form>

    @forelse ($relays as $relay)
        <article class="ob-card" style="margin-top:1rem">
            <div class="ob-admin-row">
                <div>
                    <strong>{{ parse_url($relay->inbox_url, PHP_URL_HOST) }}</strong>
                    <p class="ob-field__help" style="margin:0.35rem 0 0"><code>{{ $relay->inbox_url }}</code></p>
                    <p class="ob-field__help" style="margin:0.35rem 0 0">
                        {{ $protocols[$relay->protocol] ?? $relay->protocol }} ·
                        {{ __('openbook.admin.relays.state_'.$relay->state) }} ·
                        {{ $relay->receive_enabled ? __('openbook.admin.relays.receives') : __('openbook.admin.relays.does_not_receive') }} ·
                        {{ $relay->publish_enabled ? __('openbook.admin.relays.publishes') : __('openbook.admin.relays.does_not_publish') }}
                    </p>
                    <p class="ob-field__help" style="margin:0.35rem 0 0">
                        {{ __('openbook.admin.relays.last_success') }}:
                        {{ $relay->last_success_at?->diffForHumans() ?? __('openbook.admin.relays.never') }} ·
                        {{ __('openbook.admin.relays.last_failure') }}:
                        {{ $relay->last_failure_at?->diffForHumans() ?? __('openbook.admin.relays.never') }}
                    </p>
                    <form method="POST" action="{{ route('admin.relays.update', $relay) }}" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;margin-top:0.75rem">
                        @csrf
                        @method('PATCH')
                        <label><input type="checkbox" name="receive_enabled" value="1" @checked($relay->receive_enabled)> {{ __('openbook.admin.relays.receive_enabled') }}</label>
                        <label><input type="checkbox" name="publish_enabled" value="1" @checked($relay->publish_enabled)> {{ __('openbook.admin.relays.publish_enabled') }}</label>
                        <button type="submit" class="ob-btn ob-btn--ghost">{{ __('openbook.admin.relays.save_options') }}</button>
                    </form>
                </div>
                <div style="display:flex;gap:0.5rem;flex-wrap:wrap;justify-content:flex-end">
                    @if ($relay->state === \App\Domain\Federation\Relay::STATE_IDLE)
                        <form method="POST" action="{{ route('admin.relays.subscribe', $relay) }}">
                            @csrf
                            <button type="submit" class="ob-btn ob-btn--primary">{{ __('openbook.admin.relays.subscribe') }}</button>
                        </form>
                        <form method="POST" action="{{ route('admin.relays.destroy', $relay) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="ob-btn ob-btn--ghost">{{ __('openbook.admin.relays.remove') }}</button>
                        </form>
                    @elseif ($relay->state === \App\Domain\Federation\Relay::STATE_ACCEPTED)
                        <form method="POST" action="{{ route('admin.relays.unsubscribe', $relay) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="ob-btn ob-btn--ghost">{{ __('openbook.admin.relays.unsubscribe') }}</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('admin.relays.subscribe', $relay) }}">
                            @csrf
                            <button type="submit" class="ob-btn ob-btn--primary">{{ __('openbook.admin.relays.retry') }}</button>
                        </form>
                        <form method="POST" action="{{ route('admin.relays.unsubscribe', $relay) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="ob-btn ob-btn--ghost">{{ __('openbook.admin.relays.deactivate') }}</button>
                        </form>
                    @endif
                </div>
            </div>
            @if ($relay->last_error)
                <p class="ob-alert ob-alert--error" style="margin:1rem 0 0">{{ $relay->last_error }}</p>
            @endif
        </article>
    @empty
        <div class="ob-empty-state" style="margin-top:1.5rem"><p>{{ __('openbook.admin.relays.empty') }}</p></div>
    @endforelse

    <div style="margin-top:1.5rem">{{ $relays->links() }}</div>
@endsection
