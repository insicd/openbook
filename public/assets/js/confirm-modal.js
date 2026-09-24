/**
 * Modale di conferma per azioni del profilo (condivisione automatica,
 * condividi a utente): intercetta submit/link con data-confirm-*, mostra
 * titolo e spiegazione, poi conferma o annulla. Senza JavaScript le
 * azioni restano immediate.
 */
(function () {
    'use strict';

    var modal = document.getElementById('ob-confirm-modal');

    if (!modal) {
        return;
    }

    var titleEl = document.getElementById('ob-confirm-modal-title');
    var bodyEl = document.getElementById('ob-confirm-modal-body');
    var acceptBtn = modal.querySelector('[data-confirm-accept]');
    var pending = null;

    function closest(element, selector) {
        while (element && element !== document) {
            if (element.matches && element.matches(selector)) {
                return element;
            }

            element = element.parentElement;
        }

        return null;
    }

    function closeDetails(trigger) {
        var details = closest(trigger, 'details');

        if (details) {
            details.removeAttribute('open');
        }
    }

    function closeModal() {
        modal.hidden = true;
        document.documentElement.classList.remove('ob-confirm-modal-open');
        pending = null;
    }

    function openModal(trigger) {
        var title = trigger.getAttribute('data-confirm-title') || '';
        var body = trigger.getAttribute('data-confirm-body') || '';
        var submit = trigger.getAttribute('data-confirm-submit') || '';

        titleEl.textContent = title;
        bodyEl.textContent = body;
        acceptBtn.textContent = submit || acceptBtn.textContent;
        pending = trigger;
        modal.hidden = false;
        document.documentElement.classList.add('ob-confirm-modal-open');
        acceptBtn.focus();
    }

    function confirmPending() {
        var trigger = pending;

        if (!trigger) {
            closeModal();
            return;
        }

        closeModal();

        if (trigger.tagName === 'A') {
            window.location.href = trigger.href;
            return;
        }

        var form = closest(trigger, 'form');

        if (!form) {
            return;
        }

        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit(trigger);
        } else {
            form.submit();
        }
    }

    document.addEventListener('submit', function (event) {
        var trigger = event.submitter;

        if (!trigger || !trigger.hasAttribute('data-confirm-body') || trigger.getAttribute('data-confirm-skip') === '1') {
            return;
        }

        event.preventDefault();
        closeDetails(trigger);
        openModal(trigger);
    });

    document.addEventListener('click', function (event) {
        var link = closest(event.target, 'a[data-confirm-body]');

        if (link) {
            event.preventDefault();
            closeDetails(link);
            openModal(link);
            return;
        }

        if (closest(event.target, '[data-confirm-dismiss]')) {
            event.preventDefault();
            closeModal();
            return;
        }

        if (closest(event.target, '[data-confirm-accept]')) {
            event.preventDefault();

            if (pending && pending.tagName !== 'A') {
                pending.setAttribute('data-confirm-skip', '1');
            }

            confirmPending();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) {
            closeModal();
        }
    });
})();
