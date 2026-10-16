/** Vote once, then render the server-confirmed choices. No result polling. */
(function () {
    'use strict';

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.matches || !form.matches('[data-poll-form]')) {
            return;
        }
        event.preventDefault();
        if (form.dataset.busy === '1') {
            return;
        }
        var poll = form.closest('[data-poll-id]');
        var button = form.querySelector('button[type="submit"]');
        var feedback = poll.querySelector('[data-poll-feedback]');
        var body = new FormData(form);
        form.dataset.busy = '1';
        button.disabled = true;
        poll.setAttribute('aria-busy', 'true');
        feedback.hidden = true;
        fetch(form.action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            body: body,
        }).then(function (response) {
            if (!response.ok && response.status !== 422) {
                throw new Error('vote response');
            }
            return response.json().then(function (data) {
                if (response.status === 422) {
                    feedback.textContent = Object.values(data.errors || {}).flat().join(' ') || data.message;
                    feedback.hidden = false;
                    feedback.tabIndex = -1;
                    feedback.focus();
                    return;
                }
                var template = document.createElement('template');
                template.innerHTML = data.html;
                var updated = template.content.querySelector('[data-poll-id]');
                if (!updated) {
                    throw new Error('vote markup');
                }
                poll.replaceWith(updated);
                updated.querySelector('[data-poll-status]').textContent = data.message;
                updated.focus();
            });
        }).catch(function () {
            // Repeating the same POST is safe: the service returns the original vote.
            form.submit();
        }).finally(function () {
            delete form.dataset.busy;
            button.disabled = false;
            poll.removeAttribute('aria-busy');
        });
    });
})();
