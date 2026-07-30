/**
 * Pannelli della navbar (ricerca inline e dropdown notifiche): un solo
 * pannello aperto alla volta, chiusura con click fuori o Esc. All'apertura
 * delle notifiche segna come lette quelle non lette (stesso effetto della
 * pagina completa) e nasconde il badge, cosi' l'utente non deve lasciare
 * la pagina corrente.
 */
(function () {
    'use strict';

    var panels = Array.prototype.slice.call(document.querySelectorAll('[data-header-panel]'));

    if (panels.length === 0) {
        return;
    }

    var csrfToken = document.querySelector('meta[name="csrf-token"]');

    function closePanel(panel) {
        var toggle = panel.querySelector('[data-header-panel-toggle]');
        var content = panel.querySelector('[data-header-panel-content]');

        if (!toggle || !content) {
            return;
        }

        content.hidden = true;
        panel.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
    }

    function closeAll(except) {
        panels.forEach(function (panel) {
            if (panel !== except) {
                closePanel(panel);
            }
        });
    }

    function openPanel(panel) {
        var toggle = panel.querySelector('[data-header-panel-toggle]');
        var content = panel.querySelector('[data-header-panel-content]');

        if (!toggle || !content) {
            return;
        }

        closeAll(panel);
        content.hidden = false;
        panel.classList.add('is-open');
        toggle.setAttribute('aria-expanded', 'true');

        if (panel.getAttribute('data-header-panel') === 'search') {
            var input = panel.querySelector('[data-header-search-input]');
            if (input) {
                input.focus();
                input.select();
            }
        }

        if (panel.getAttribute('data-header-panel') === 'notifications') {
            markNotificationsRead(toggle);
        }
    }

    function markNotificationsRead(toggle) {
        var url = toggle.getAttribute('data-mark-read-url');

        document.querySelectorAll('[data-notifications-badge]').forEach(function (badge) {
            badge.remove();
        });

        document.dispatchEvent(new CustomEvent('openbook:notifications-read'));

        if (!url || !csrfToken) {
            return;
        }

        fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken.getAttribute('content'),
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
            credentials: 'same-origin',
        }).catch(function () {
            // Il badge e' gia' nascosto in UI; un fallimento di rete non
            // deve disturbare l'apertura del pannello.
        });
    }

    panels.forEach(function (panel) {
        var toggle = panel.querySelector('[data-header-panel-toggle]');

        if (!toggle) {
            return;
        }

        toggle.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();

            if (panel.classList.contains('is-open')) {
                closePanel(panel);
            } else {
                openPanel(panel);
            }
        });
    });

    document.addEventListener('click', function (event) {
        panels.forEach(function (panel) {
            if (panel.classList.contains('is-open') && !panel.contains(event.target)) {
                closePanel(panel);
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeAll();
        }
    });
})();
