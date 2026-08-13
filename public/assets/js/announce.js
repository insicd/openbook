/**
 * Condividi/annulla condivisione senza ricaricare la pagina: intercetta i
 * form [data-announce-form], chiama l'endpoint con Accept: application/json
 * e aggiorna contatore/stato del menu. Senza JS resta il POST classico.
 */
(function () {
    'use strict';

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute('content') : '';
    }

    function methodField(form) {
        return form.querySelector('input[name="_method"]');
    }

    function menuFromForm(form) {
        return form.closest('[data-announce-menu]');
    }

    function applyState(menu, data) {
        var announced = !!data.announced;
        var count = typeof data.announces_count === 'number' ? data.announces_count : 0;
        var summary = menu.querySelector('[data-announce-summary]');
        var countEl = summary ? summary.querySelector('.ob-post__action-count') : null;
        var announceForm = menu.querySelector('[data-announce-action]');
        var unannounceForm = menu.querySelector('[data-unannounce-action]');
        var labelTemplate = menu.getAttribute(announced ? 'data-label-announced' : 'data-label-announce') || '';
        var label = data.label || labelTemplate.replace('__COUNT__', String(count));

        menu.setAttribute('data-announced', announced ? '1' : '0');

        if (summary) {
            summary.classList.toggle('ob-post__action--active', announced);
            summary.setAttribute('aria-label', label);
        }

        if (countEl) {
            countEl.textContent = String(count);
        }

        if (announceForm) {
            announceForm.hidden = announced;
        }

        if (unannounceForm) {
            unannounceForm.hidden = !announced;
        }

        menu.removeAttribute('open');
    }

    function submitAnnounce(form) {
        if (form.dataset.announceBusy === '1') {
            return;
        }

        var menu = menuFromForm(form);

        if (!menu) {
            form.submit();

            return;
        }

        form.dataset.announceBusy = '1';

        var methodInput = methodField(form);
        var httpMethod = methodInput ? String(methodInput.value).toUpperCase() : 'POST';

        fetch(form.action, {
            method: httpMethod,
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
            credentials: 'same-origin',
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('announce failed');
            }

            return response.json();
        }).then(function (data) {
            applyState(menu, data);
        }).catch(function () {
            form.submit();
        }).finally(function () {
            delete form.dataset.announceBusy;
        });
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!form || !form.matches || !form.matches('[data-announce-form]')) {
            return;
        }

        event.preventDefault();
        submitAnnounce(form);
    });
})();
