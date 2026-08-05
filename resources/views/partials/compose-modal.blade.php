{{--
    Dialog "nuovo post" aperto dal pulsante + fuori dalla home.
    Stesso composer della home; id distinti per evitare conflitti con
    eventuali composer inline nella pagina.
--}}
<div
    class="ob-compose-modal"
    id="ob-compose-modal"
    role="dialog"
    aria-modal="true"
    aria-labelledby="ob-compose-modal-title"
    hidden
    @if (old('composer_ui') === 'modal') data-open-on-load="1" @endif
>
    <button type="button" class="ob-compose-modal__backdrop" data-compose-modal-close tabindex="-1" aria-label="{{ __('openbook.nav.new_post_close') }}"></button>

    <div class="ob-compose-modal__dialog">
        <div class="ob-compose-modal__header">
            <h2 id="ob-compose-modal-title" class="ob-compose-modal__title">{{ __('openbook.nav.new_post_dialog') }}</h2>
            <button type="button" class="ob-icon-btn ob-compose-modal__close" data-compose-modal-close aria-label="{{ __('openbook.nav.new_post_close') }}">
                <x-icon name="close" />
            </button>
        </div>

        <div class="ob-compose-modal__body">
            @include('posts._composer', [
                'formId' => 'ob-modal-composer',
                'bodyId' => 'modal-composer-body',
                'prefix' => 'modal_composer',
                'composerCommunities' => $modalComposerCommunities ?? collect(),
                'inModal' => true,
                'composerUi' => 'modal',
            ])
        </div>
    </div>
</div>
