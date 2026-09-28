/** Il listener sul documento copre anche le righe inserite dallo scroll. */
(function () {
    'use strict';

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.matches || !form.matches('[data-community-membership-form]')) {
            return;
        }

        event.preventDefault();
        if (form.dataset.busy === '1') {
            return;
        }

        var row = form.closest('.ob-community-list__item');
        var directory = form.closest('[data-community-directory]');
        var button = form.querySelector('button[type="submit"]');
        var methodField = form.querySelector('input[name="_method"]');
        var csrf = form.querySelector('input[name="_token"]');
        var oldError = row.querySelector('[data-community-action-error]');
        if (oldError) {
            oldError.remove();
        }

        form.dataset.busy = '1';
        button.disabled = true;

        fetch(form.action, {
            method: methodField ? methodField.value.toUpperCase() : 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf ? csrf.value : '',
            },
            credentials: 'same-origin',
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('community membership failed');
            }
            return response.json();
        }).then(function (data) {
            var template = document.createElement('template');
            template.innerHTML = data.html || '';
            var updated = template.content.firstElementChild;
            if (!updated || !updated.matches('.ob-community-list__item')) {
                throw new Error('missing community row');
            }

            var scope = directory.getAttribute('data-scope');
            if (data.listed && data.listed[scope] === false) {
                row.remove();
            } else {
                row.replaceWith(updated);
            }
        }).catch(function () {
            var error = document.createElement('span');
            error.setAttribute('data-community-action-error', '');
            error.setAttribute('role', 'alert');
            error.className = 'ob-field__error';
            error.textContent = directory.getAttribute('data-action-error-label') || '';
            form.insertAdjacentElement('afterend', error);
        }).finally(function () {
            delete form.dataset.busy;
            button.disabled = false;
        });
    });
})();
