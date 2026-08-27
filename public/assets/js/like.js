/**
 * Like/unlike senza ricaricare la pagina: intercetta i form [data-like-form],
 * chiama l'endpoint con Accept: application/json e aggiorna contatore/stato
 * del bottone. Senza JS resta il POST classico con redirect+fragment.
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

    function ensureMethod(form, method) {
        var field = methodField(form);

        if (method === 'POST') {
            if (field) {
                field.remove();
            }
            return;
        }

        if (!field) {
            field = document.createElement('input');
            field.type = 'hidden';
            field.name = '_method';
            form.appendChild(field);
        }

        field.value = method;
    }

    function reactionList(form) {
        var group = form.closest('.ob-post__action-group');

        return group ? group.querySelector('[data-reaction-list]') : null;
    }

    function applyState(form, data) {
        var liked = !!data.liked;
        var count = typeof data.likes_count === 'number' ? data.likes_count : 0;
        var button = form.querySelector('button[type="submit"]');
        var list = reactionList(form);
        var countEl = list
            ? list.querySelector('[data-reaction-count]')
            : form.querySelector('.ob-post__action-count');
        var likeAction = form.getAttribute('data-like-action');
        var unlikeAction = form.getAttribute('data-unlike-action');
        var labelTemplate = form.getAttribute(liked ? 'data-label-liked' : 'data-label-like') || '';
        var label = (data.label || labelTemplate.replace('__COUNT__', String(count)));

        form.setAttribute('data-liked', liked ? '1' : '0');
        form.action = liked ? unlikeAction : likeAction;
        ensureMethod(form, liked ? 'DELETE' : 'POST');

        if (button) {
            button.classList.toggle('ob-post__action--active', liked);
            button.setAttribute('aria-label', label);
        }

        if (countEl) {
            countEl.textContent = String(count);
        }

        if (list) {
            var listLabel = list.getAttribute('data-label') || '';

            list.classList.toggle('ob-reaction-list--empty', count < 1);
            if (count < 1) {
                list.removeAttribute('open');
            }
            delete list.dataset.loaded;

            if (countEl && listLabel) {
                countEl.setAttribute('aria-label', listLabel.replace('__COUNT__', String(count)));
                countEl.setAttribute('aria-disabled', count < 1 ? 'true' : 'false');
            }
        }
    }

    function submitLike(form) {
        if (form.dataset.likeBusy === '1') {
            return;
        }

        form.dataset.likeBusy = '1';

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
                throw new Error('like failed');
            }

            return response.json();
        }).then(function (data) {
            applyState(form, data);
        }).catch(function () {
            // Fallback: submit classico (redirect con fragment).
            form.submit();
        }).finally(function () {
            delete form.dataset.likeBusy;
        });
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!form || !form.matches || !form.matches('[data-like-form]')) {
            return;
        }

        event.preventDefault();
        submitLike(form);
    });
})();
