/**
 * Autocomplete destinatario per aprire una nuova chat da /messaggi.
 */
(function () {
    'use strict';

    var input = document.getElementById('ob-message-recipient');

    if (!input) {
        return;
    }

    var endpoint = input.getAttribute('data-suggest-url') || '';
    var listLabel = input.getAttribute('data-suggest-label') || 'Destinatari';
    var emptyLabel = input.getAttribute('data-suggest-empty') || '';
    var debounceMs = 180;
    var minLength = 1;

    var debounceTimer = null;
    var abortController = null;
    var suggestions = [];
    var activeIndex = -1;
    var panel = null;
    var list = null;

    function ensurePanel() {
        if (panel) {
            return;
        }

        panel = document.createElement('div');
        panel.className = 'ob-mention-suggest ob-message-recipient-suggest';
        panel.hidden = true;
        panel.setAttribute('role', 'listbox');
        panel.setAttribute('aria-label', listLabel);

        list = document.createElement('ul');
        list.className = 'ob-mention-suggest__list';
        panel.appendChild(list);
        document.body.appendChild(panel);
    }

    function hidePanel() {
        if (!panel) {
            return;
        }

        panel.hidden = true;
        list.innerHTML = '';
        suggestions = [];
        activeIndex = -1;
    }

    function positionPanel() {
        if (!panel || !input) {
            return;
        }

        var rect = input.getBoundingClientRect();

        panel.style.position = 'fixed';
        panel.style.left = rect.left + 'px';
        panel.style.top = rect.bottom + 4 + 'px';
        panel.style.width = Math.max(rect.width, 280) + 'px';
        panel.style.zIndex = '1200';
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function openSuggestion(item) {
        if (item && item.open_url) {
            window.location.href = item.open_url;
        }
    }

    function renderSuggestions() {
        ensurePanel();
        list.innerHTML = '';

        if (suggestions.length === 0) {
            if ((input.value || '').trim().length >= minLength) {
                var empty = document.createElement('p');
                empty.className = 'ob-mention-suggest__empty';
                empty.textContent = emptyLabel;
                list.appendChild(empty);
                panel.hidden = false;
                positionPanel();
            } else {
                hidePanel();
            }

            return;
        }

        suggestions.forEach(function (item, index) {
            var li = document.createElement('li');
            li.className = 'ob-mention-suggest__item' + (index === activeIndex ? ' is-active' : '');
            li.setAttribute('role', 'option');

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'ob-mention-suggest__button';

            if (item.avatar_url) {
                var img = document.createElement('img');
                img.className = 'ob-mention-suggest__avatar';
                img.src = item.avatar_url;
                img.alt = '';
                button.appendChild(img);
            } else {
                var fallback = document.createElement('span');
                fallback.className = 'ob-mention-suggest__avatar ob-mention-suggest__avatar--fallback';
                fallback.textContent = (item.display_name || item.handle || '?').charAt(0).toUpperCase();
                button.appendChild(fallback);
            }

            var meta = document.createElement('span');
            meta.className = 'ob-mention-suggest__meta';

            var name = document.createElement('span');
            name.className = 'ob-mention-suggest__name';
            name.textContent = item.display_name || item.handle;
            meta.appendChild(name);

            var handle = document.createElement('span');
            handle.className = 'ob-mention-suggest__handle';
            handle.textContent = '@' + item.handle;
            meta.appendChild(handle);

            button.appendChild(meta);
            button.addEventListener('mousedown', function (event) {
                event.preventDefault();
                openSuggestion(item);
            });

            li.appendChild(button);
            list.appendChild(li);
        });

        panel.hidden = false;
        positionPanel();
    }

    function fetchSuggestions(query) {
        if (abortController) {
            abortController.abort();
        }

        abortController = typeof AbortController === 'function' ? new AbortController() : null;

        var url = endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(query);
        var options = {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        };

        if (abortController) {
            options.signal = abortController.signal;
        }

        fetch(url, options)
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('suggest failed');
                }

                return response.json();
            })
            .then(function (data) {
                if ((input.value || '').trim() !== query) {
                    return;
                }

                suggestions = Array.isArray(data.suggestions) ? data.suggestions : [];
                activeIndex = suggestions.length > 0 ? 0 : -1;
                renderSuggestions();
            })
            .catch(function (error) {
                if (error && error.name === 'AbortError') {
                    return;
                }

                hidePanel();
            });
    }

    function scheduleFetch() {
        var query = (input.value || '').trim().replace(/^@+/, '');

        window.clearTimeout(debounceTimer);

        if (query.length < minLength) {
            hidePanel();
            return;
        }

        debounceTimer = window.setTimeout(function () {
            fetchSuggestions(query);
        }, debounceMs);
    }

    input.addEventListener('input', scheduleFetch);

    input.addEventListener('keydown', function (event) {
        if (panel && !panel.hidden && suggestions.length > 0) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                activeIndex = Math.min(activeIndex + 1, suggestions.length - 1);
                renderSuggestions();
                return;
            }

            if (event.key === 'ArrowUp') {
                event.preventDefault();
                activeIndex = Math.max(activeIndex - 1, 0);
                renderSuggestions();
                return;
            }

            if (event.key === 'Enter' && activeIndex >= 0 && suggestions[activeIndex]) {
                event.preventDefault();
                openSuggestion(suggestions[activeIndex]);
                return;
            }

            if (event.key === 'Escape') {
                hidePanel();
                return;
            }
        }
    });

    input.addEventListener('blur', function () {
        window.setTimeout(hidePanel, 150);
    });

    window.addEventListener('resize', positionPanel);
    window.addEventListener('scroll', positionPanel, true);
})();
