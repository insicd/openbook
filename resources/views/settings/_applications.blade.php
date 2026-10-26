@inject('dates', 'App\Support\UserDate')

<section class="ob-settings-section" aria-labelledby="settings-applications-title">
    <div class="ob-connected-applications-intro">
        <h2 id="settings-applications-title">{{ __('oauth.applications') }}</h2>
        <p>{{ __('oauth.applications_help') }}</p>
        @if ($connectedApplications->isEmpty())
            <p>{{ __('oauth.no_applications') }}</p>
        @endif
    </div>

    @foreach ($connectedApplications as $access)
        @php
            $expires = $access->revoked ? null : $access->expires_at;
            if ($access->refreshToken && ! $access->refreshToken->revoked && ($expires === null || $access->refreshToken->expires_at?->greaterThan($expires))) {
                $expires = $access->refreshToken->expires_at;
            }
        @endphp
        <article class="ob-card ob-connected-application">
            <div class="ob-connected-application__details">
                <h3>{{ $access->client->name }}</h3>
                <dl class="ob-connected-application__dates">
                    <div>
                        <dt>{{ __('oauth.access_created') }}</dt>
                        <dd><time datetime="{{ $access->created_at->toIso8601String() }}">{{ $dates->format($access->created_at) }}</time></dd>
                    </div>
                    <div>
                        <dt>{{ __('oauth.access_expires') }}</dt>
                        <dd><time datetime="{{ $expires->toIso8601String() }}">{{ $dates->format($expires) }}</time></dd>
                    </div>
                </dl>
            </div>
            <form method="POST" action="{{ route('settings.applications.destroy', ['access' => $access->id]) }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="ob-btn ob-btn--ghost ob-btn--small" aria-label="{{ __('oauth.close_named_access', ['app' => $access->client->name]) }}">{{ __('oauth.close_access') }}</button>
            </form>
        </article>
    @endforeach
    {{ $connectedApplications->links() }}
</section>
