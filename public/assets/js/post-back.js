/**
 * Ritorno dal dettaglio post: conserva solo la distanza nella cronologia,
 * senza salvare il feed o cambiare l'invio dei form.
 */
(function () {
    'use strict';

    var key = 'ob.postBack';
    var bar = document.querySelector('[data-post-back]');
    var path = window.location.pathname;
    var depth = 0;

    function readContext() {
        try {
            return JSON.parse(sessionStorage.getItem(key));
        } catch (e) {
            return null;
        }
    }

    function clearContext() {
        try { sessionStorage.removeItem(key); } catch (e) {}
    }

    function saveContext(pending) {
        try {
            sessionStorage.setItem(key, JSON.stringify({ path: path, depth: depth, pending: pending }));
        } catch (e) {}
    }

    function validContext(context) {
        return context && context.path === path
            && Number.isSafeInteger(context.depth) && context.depth > 0
            && context.depth < window.history.length;
    }

    function rememberEntry() {
        // Stato locale all'entrata del post: refresh e back non sono nuovi submit.
        var state = Object.assign({}, window.history.state);
        state.obPostBack = { path: path, depth: depth };
        window.history.replaceState(state, '', window.location.href);
        saveContext(false);
    }

    function init(restored) {
        if (!bar) {
            clearContext();
            return;
        }

        var navigation = performance.getEntriesByType('navigation')[0];
        var context = readContext();
        var entry = window.history.state && window.history.state.obPostBack;
        var referrer = null;
        try { referrer = new URL(document.referrer); } catch (e) {}

        depth = 0;
        if (restored || (navigation && navigation.type !== 'navigate')) {
            if (validContext(entry)) {
                depth = entry.depth;
            }
        } else if (referrer && referrer.origin === window.location.origin) {
            if (referrer.pathname !== path && window.history.length > 1) {
                depth = 1;
            } else if (validContext(context) && context.pending) {
                // Il POST con redirect aggiunge un'entrata, anche con errori di validazione.
                depth = context.depth + 1;
            }
        }

        bar.hidden = !depth;
        if (depth) {
            rememberEntry();
        } else {
            clearContext();
        }
    }

    init(false);
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            init(true);
        }
    });

    if (!bar) {
        return;
    }

    bar.querySelector('[data-post-back-button]').addEventListener('click', function () {
        if (!depth) {
            return;
        }
        var steps = depth;
        clearContext();
        bar.hidden = true;
        window.history.go(-steps);
    });

    document.addEventListener('submit', function (event) {
        // I listener AJAX esistenti hanno gia' annullato i submit che gestiscono.
        if (depth && !event.defaultPrevented) {
            saveContext(true);
        }
    });

    document.addEventListener('click', function (event) {
        var link = event.target.closest('a[href]');
        if (!link || event.defaultPrevented || event.button !== 0
            || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey
            || link.hasAttribute('download') || (link.target && link.target !== '_self')) {
            return;
        }
        var destination = new URL(link.href);
        if (destination.origin !== window.location.origin || destination.pathname !== path) {
            clearContext();
        }
    });

    window.addEventListener('hashchange', function () {
        if (!depth) {
            return;
        }
        var entry = window.history.state && window.history.state.obPostBack;
        if (validContext(entry)) {
            depth = entry.depth;
        } else {
            depth += 1;
            rememberEntry();
        }
    });
})();
