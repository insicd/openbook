<div
    id="ob-confirm-modal"
    class="ob-confirm-modal"
    hidden
    role="dialog"
    aria-modal="true"
    aria-labelledby="ob-confirm-modal-title"
    aria-describedby="ob-confirm-modal-body"
>
    <button type="button" class="ob-confirm-modal__backdrop" data-confirm-dismiss tabindex="-1" aria-hidden="true"></button>
    <div class="ob-confirm-modal__dialog">
        <h2 id="ob-confirm-modal-title" class="ob-confirm-modal__title"></h2>
        <p id="ob-confirm-modal-body" class="ob-confirm-modal__body"></p>
        <div class="ob-confirm-modal__actions">
            <button type="button" class="ob-btn ob-btn--ghost" data-confirm-dismiss>
                {{ __('openbook.profile.actions_cancel') }}
            </button>
            <button type="button" class="ob-btn ob-btn--primary" data-confirm-accept>
                {{ __('openbook.profile.actions_continue') }}
            </button>
        </div>
    </div>
</div>
