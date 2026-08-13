/**
 * Autocomplete per i campi di ricerca (navbar e pagina /cerca): mentre si
 * digita vengono proposti persone (locali discoverable e remoti in cache)
 * e hashtag. Stesso pannello visuale delle menzioni; Enter sul suggerimento
 * apre il profilo/tag, Enter senza selezione invia la ricerca completa.
 */
(function () {
    'use strict';

    var config = document.getElementById('ob-search-suggest');
    var inputs = document.querySelectorAll('[data-search-suggest]');

    if (!config || inputs.length === 0) {
        return;
    }

    var endpoint = config.getAttribute('data-url') || '';
    var listLabel = config.getAttribute('data-label') || '';
    var emptyLabel = config.getAttribute('data-empty') || '';
    var peopleLabel = config.getAttribute('data-people') || '';
    var hashtagsLabel = config.getAttribute('data-hashtags') || '';
    var debounceMs = 180;
    var minLength = parseInt(config.getAttribute('data-min-length') || '2', 10);

    if (!endpoint) {
        return;
    }

    var debounceTimer = null;
    var abortController = null;
    var suggestions = [];
    var activeIndex = -1;
    var activeInput = null;
    var panel = null;
    var list = null;

    function ensurePanel() {
        if (panel) {
            return;
        }

        panel = document.createElement('div');
        panel.className = 'ob-mention-suggest ob-search-suggest';
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
        if (!panel || !activeInput) {
            return;
        }

        var rect = activeInput.getBoundingClientRect();

        panel.style.position = 'fixed';
        panel.style.left = rect.left + 'px';
        panel.style.top = rect.bottom + 4 + 'px';
        panel.style.width = Math.max(rect.width, 280) + 'px';
        panel.style.zIndex = '1200';
    }

    function openSuggestion(item) {
        if (item && item.url) {
            window.location.href = item.url;
        }
    }

    function typeLabel(type) {
        if (type === 'hashtag') {
            return hashtagsLabel;
        }

        if (type === 'person') {
            return peopleLabel;
        }

        return '';
    }

    function renderSuggestions() {
        ensurePanel();
        list.innerHTML = '';

        if (suggestions.length === 0) {
            if (activeInput && (activeInput.value || '').trim().length >= minLength) {
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

        var lastType = null;

        suggestions.forEach(function (item, index) {
            if (item.type && item.type !== lastType) {
                var heading = document.createElement('li');
                heading.className = 'ob-search-suggest__heading';
                heading.setAttribute('role', 'presentation');
                heading.textContent = typeLabel(item.type);
                list.appendChild(heading);
                lastType = item.type;
            }

            var li = document.createElement('li');
            li.className = 'ob-mention-suggest__item' + (index === activeIndex ? ' is-active' : '');
            li.setAttribute('role', 'option');

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'ob-mention-suggest__button';

            if (item.type === 'hashtag') {
                var hashAvatar = document.createElement('span');
                hashAvatar.className = 'ob-mention-suggest__avatar ob-mention-suggest__avatar--fallback ob-search-suggest__hash';
                hashAvatar.textContent = '#';
                button.appendChild(hashAvatar);
            } else if (item.avatar_url) {
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

            if (item.type !== 'hashtag') {
                var handle = document.createElement('span');
                handle.className = 'ob-mention-suggest__handle';
                handle.textContent = '@' + item.handle;
                meta.appendChild(handle);
            }

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

    function fetchSuggestions(input, query) {
        if (abortController) {
            abortController.abort();
        }

        abortController = typeof AbortController === 'function' ? new AbortController() : null;
        activeInput = input;

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
                if (!activeInput || (activeInput.value || '').trim() !== query) {
                    return;
                }

                suggestions = Array.isArray(data.suggestions) ? data.suggestions : [];
                activeIndex = -1;
                renderSuggestions();
            })
            .catch(function (error) {
                if (error && error.name === 'AbortError') {
                    return;
                }

                hidePanel();
            });
    }

    function scheduleFetch(input) {
        var query = (input.value || '').trim();

        window.clearTimeout(debounceTimer);
        activeInput = input;

        if (query.length < minLength) {
            hidePanel();
            return;
        }

        debounceTimer = window.setTimeout(function () {
            fetchSuggestions(input, query);
        }, debounceMs);
    }

    function bindInput(input) {
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('aria-autocomplete', 'list');

        input.addEventListener('input', function () {
            scheduleFetch(input);
        });

        input.addEventListener('focus', function () {
            if ((input.value || '').trim().length >= minLength) {
                scheduleFetch(input);
            }
        });

        input.addEventListener('keydown', function (event) {
            if (panel && !panel.hidden && suggestions.length > 0 && activeInput === input) {
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    activeIndex = Math.min(activeIndex + 1, suggestions.length - 1);
                    if (activeIndex < 0) {
                        activeIndex = 0;
                    }
                    renderSuggestions();
                    return;
                }

                if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    if (activeIndex <= 0) {
                        activeIndex = -1;
                    } else {
                        activeIndex = activeIndex - 1;
                    }
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
            window.setTimeout(function () {
                if (activeInput === input) {
                    hidePanel();
                }
            }, 150);
        });
    }

    inputs.forEach(bindInput);

    window.addEventListener('resize', positionPanel);
    window.addEventListener('scroll', positionPanel, true);
})();
