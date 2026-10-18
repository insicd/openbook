<section id="push-notification-settings" class="ob-settings-section" aria-labelledby="settings-notifications-title" hidden
    data-vapid-public-key="{{ $pushVapidPublicKey }}"
    data-subscribe-url="{{ route('settings.push_subscriptions.store') }}"
    data-unsubscribe-url="{{ route('settings.push_subscriptions.destroy') }}"
    data-subscription-hashes='@json($pushSubscriptionHashes)'
    data-label-inactive="{{ __('openbook.settings.push_inactive') }}"
    data-label-active="{{ __('openbook.settings.push_active') }}"
    data-label-reattach="{{ __('openbook.settings.push_reattach') }}"
    data-label-unsupported="{{ __('openbook.settings.push_unsupported') }}"
    data-label-denied="{{ __('openbook.settings.push_denied') }}"
    data-label-error="{{ __('openbook.settings.push_error') }}"
>
    <div class="ob-card">
        <h2 id="settings-notifications-title">{{ __('openbook.settings.tab_notifications') }}</h2>
        <span class="ob-push-settings__label">{{ __('openbook.settings.push_label') }}</span>
        <p class="ob-field__help" data-push-status aria-live="polite"></p>
        <p class="ob-field__help">{{ __('openbook.settings.push_ios_help', ['app' => config('app.name')]) }}</p>
    </div>
    <div class="ob-settings-actions">
        <button type="button" class="ob-btn ob-btn--primary" data-push-enable hidden>
            {{ __('openbook.settings.push_enable') }}
        </button>
        <button type="button" class="ob-btn ob-btn--secondary" data-push-disable hidden>
            {{ __('openbook.settings.push_disable') }}
        </button>
    </div>
</section>
