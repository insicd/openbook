(function () {
    'use strict';

    var widget = document.querySelector('[data-world-suggestions]');

    if (!widget) {
        return;
    }

    var content = widget.querySelector('[data-world-suggestions-content]');
    var loading = false;

    function showError() {
        var message = document.createElement('p');
        message.className = 'ob-field__help';
        message.textContent = widget.getAttribute('data-error-label');

        var retry = document.createElement('a');
        retry.href = widget.getAttribute('data-url');
        retry.textContent = widget.getAttribute('data-retry-label');
        retry.addEventListener('click', function (event) {
            event.preventDefault();
            load();
        });

        content.replaceChildren(message, retry);
    }

    function load() {
        if (loading) {
            return;
        }

        loading = true;
        fetch(widget.getAttribute('data-url'), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('unexpected response status ' + response.status);
                }

                return response.text();
            })
            .then(function (html) {
                if (!html.trim()) {
                    widget.remove();
                    return;
                }

                var result = new DOMParser()
                    .parseFromString(html, 'text/html')
                    .querySelector('[data-world-suggestions-result]');

                if (!result) {
                    throw new Error('missing suggestions fragment');
                }

                content.replaceChildren(...Array.from(result.childNodes));
            })
            .catch(showError)
            .finally(function () {
                loading = false;
            });
    }

    load();
})();
