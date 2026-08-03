/**
 * Autocomplete menzioni (@) per textarea del composer e dei commenti.
 * Interroga /menzioni/suggerimenti e inserisce @user o @user@dominio.
 */
(function () {
    'use strict';

    var endpointNode = document.getElementById('ob-mention-suggest');
    if (!endpointNode) {
        return;
    }

    var endpoint = endpointNode.getAttribute('data-url') || '';
    var emptyLabel = endpointNode.getAttribute('data-empty') || '';
    var listLabel = endpointNode.getAttribute('data-label') || 'Mentions';

    if (!endpoint) {
        return;
    }

    var debounceMs = 180;
    var activeField = null;
    var activeToken = null;
    var activeIndex = -1;
    var suggestions = [];
    var debounceTimer = null;
    var abortController = null;
    var panel = null;
    var list = null;

    function ensurePanel() {
        if (panel) {
            return;
        }

        panel = document.createElement('div');
        panel.className = 'ob-mention-suggest';
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
        activeToken = null;
    }

    function getMentionToken(field) {
        if (!field || typeof field.selectionStart !== 'number') {
            return null;
        }

        var pos = field.selectionStart;
        if (field.selectionEnd !== pos) {
            return null;
        }

        var before = field.value.slice(0, pos);
        var match = before.match(/(^|[^a-zA-Z0-9_])@([a-zA-Z0-9_]{1,32}(?:@[a-zA-Z0-9.\-]*)?)$/);

        if (!match) {
            return null;
        }

        var query = match[2];
        if (!query) {
            return null;
        }

        return {
            query: query,
            start: pos - query.length - 1,
            end: pos,
        };
    }

    function positionPanel(field) {
        ensurePanel();
        var rect = field.getBoundingClientRect();
        var maxWidth = Math.min(360, Math.max(220, rect.width));
        panel.style.width = maxWidth + 'px';
        panel.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - maxWidth - 8)) + 'px';

        var top = rect.bottom + 6;
        panel.hidden = false;
        var panelHeight = panel.offsetHeight || 0;
        if (top + panelHeight > window.innerHeight - 8 && rect.top > panelHeight + 8) {
            top = rect.top - panelHeight - 6;
        }
        panel.style.top = Math.max(8, top) + 'px';
    }

    function renderSuggestions() {
        ensurePanel();
        list.innerHTML = '';

        if (suggestions.length === 0) {
            var empty = document.createElement('li');
            empty.className = 'ob-mention-suggest__empty';
            empty.textContent = emptyLabel;
            list.appendChild(empty);
            positionPanel(activeField);
            return;
        }

        suggestions.forEach(function (item, index) {
            var li = document.createElement('li');
            li.className = 'ob-mention-suggest__item' + (index === activeIndex ? ' is-active' : '');
            li.setAttribute('role', 'option');
            li.setAttribute('aria-selected', index === activeIndex ? 'true' : 'false');

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
                chooseSuggestion(index);
            });

            li.appendChild(button);
            list.appendChild(li);
        });

        positionPanel(activeField);
    }

    function chooseSuggestion(index) {
        if (!activeField || !activeToken || !suggestions[index]) {
            return;
        }

        var item = suggestions[index];
        var value = activeField.value || '';
        var insert = item.insert || ('@' + item.handle + ' ');
        var max = activeField.maxLength > 0 ? activeField.maxLength : null;
        var nextLength = value.length - (activeToken.end - activeToken.start) + insert.length;

        if (max !== null && nextLength > max) {
            return;
        }

        activeField.value = value.slice(0, activeToken.start) + insert + value.slice(activeToken.end);
        var caret = activeToken.start + insert.length;
        activeField.focus();
        if (typeof activeField.setSelectionRange === 'function') {
            activeField.setSelectionRange(caret, caret);
        }
        activeField.dispatchEvent(new Event('input', { bubbles: true }));
        hidePanel();
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
                    throw new Error('mention suggest failed');
                }
                return response.json();
            })
            .then(function (data) {
                if (!activeField || !activeToken || activeToken.query !== query) {
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

    function scheduleFetch(query) {
        window.clearTimeout(debounceTimer);
        debounceTimer = window.setTimeout(function () {
            fetchSuggestions(query);
        }, debounceMs);
    }

    function onFieldInput(field) {
        activeField = field;
        activeToken = getMentionToken(field);

        if (!activeToken) {
            hidePanel();
            return;
        }

        scheduleFetch(activeToken.query);
    }

    function onFieldKeydown(event, field) {
        if (!panel || panel.hidden || suggestions.length === 0 || activeField !== field) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            activeIndex = (activeIndex + 1) % suggestions.length;
            renderSuggestions();
            return;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            activeIndex = (activeIndex - 1 + suggestions.length) % suggestions.length;
            renderSuggestions();
            return;
        }

        if (event.key === 'Enter' || event.key === 'Tab') {
            if (activeIndex >= 0) {
                event.preventDefault();
                chooseSuggestion(activeIndex);
            }
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            hidePanel();
        }
    }

    function bindField(field) {
        if (!field || field.dataset.mentionBound === '1') {
            return;
        }

        field.dataset.mentionBound = '1';
        field.setAttribute('autocomplete', 'off');

        field.addEventListener('input', function () {
            onFieldInput(field);
        });
        field.addEventListener('keydown', function (event) {
            onFieldKeydown(event, field);
        });
        field.addEventListener('blur', function () {
            window.setTimeout(function () {
                if (activeField === field) {
                    hidePanel();
                }
            }, 150);
        });
        field.addEventListener('click', function () {
            onFieldInput(field);
        });
    }

    function scan() {
        document.querySelectorAll('textarea[data-mention-autocomplete]').forEach(bindField);
    }

    document.addEventListener('DOMContentLoaded', scan);
    window.addEventListener('resize', function () {
        if (panel && !panel.hidden && activeField) {
            positionPanel(activeField);
        }
    });
    window.addEventListener('scroll', function () {
        if (panel && !panel.hidden && activeField) {
            positionPanel(activeField);
        }
    }, true);

    // Reply forms vengono mostrati dopo il load: ri-scansiona al click.
    document.addEventListener('click', function () {
        window.setTimeout(scan, 0);
    });
})();
