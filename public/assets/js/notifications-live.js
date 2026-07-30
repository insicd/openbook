/**
 * Aggiornamento live di badge e dropdown notifiche: polling leggero sul
 * feed JSON, in pausa quando la scheda e' nascosta. Nessun websocket.
 */
(function () {
    'use strict';

    var toggle = document.getElementById('ob-notifications-toggle');

    if (!toggle) {
        return;
    }

    var feedUrl = toggle.getAttribute('data-notifications-feed-url');

    if (!feedUrl) {
        return;
    }

    var pollMs = parseInt(toggle.getAttribute('data-notifications-poll-ms') || '30000', 10);
    var panelRoot = toggle.closest('[data-header-panel="notifications"]');
    var list = panelRoot ? panelRoot.querySelector('[data-notifications-list]') : null;
    var emptyLabel = panelRoot ? panelRoot.getAttribute('data-notifications-empty') || '' : '';
    var indexUrl = panelRoot ? panelRoot.getAttribute('data-notifications-index') || '/notifiche' : '/notifiche';
    var lastFingerprint = null;
    var timer = null;
    var inFlight = false;

    function badgeLabel(count) {
        return count > 9 ? '9+' : String(count);
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function setBadges(count) {
        var dots = document.querySelectorAll('[data-notifications-badge]');
        var headerToggle = document.getElementById('ob-notifications-toggle');
        var sideNav = document.querySelector('[data-notifications-nav]');

        if (count <= 0) {
            dots.forEach(function (el) {
                el.remove();
            });

            return;
        }

        var label = badgeLabel(count);

        if (headerToggle && !headerToggle.querySelector('[data-notifications-badge]')) {
            var headerBadge = document.createElement('span');
            headerBadge.className = 'ob-badge-dot';
            headerBadge.setAttribute('data-notifications-badge', '');
            headerToggle.appendChild(headerBadge);
        }

        if (sideNav && !sideNav.querySelector('[data-notifications-badge]')) {
            var sideBadge = document.createElement('span');
            sideBadge.className = 'ob-badge-count';
            sideBadge.setAttribute('data-notifications-badge', '');
            sideNav.appendChild(sideBadge);
        }

        document.querySelectorAll('[data-notifications-badge]').forEach(function (el) {
            el.textContent = label;
        });
    }

    function renderAvatar(item) {
        if (item.actor_avatar) {
            return (
                '<div class="ob-avatar" style="width:36px;height:36px;font-size:0.95rem" aria-hidden="true">' +
                '<img src="' +
                escapeHtml(item.actor_avatar) +
                '" alt="">' +
                '</div>'
            );
        }

        return (
            '<div class="ob-avatar" style="width:36px;height:36px;font-size:0.95rem" aria-hidden="true">' +
            escapeHtml(item.actor_initial || '?') +
            '</div>'
        );
    }

    function renderList(notifications) {
        if (!list) {
            return;
        }

        if (!notifications || notifications.length === 0) {
            list.innerHTML = '<p class="ob-header-dropdown__empty">' + escapeHtml(emptyLabel) + '</p>';

            return;
        }

        list.innerHTML = notifications
            .map(function (item) {
                var classes = 'ob-header-notification' + (item.unread ? ' ob-header-notification--unread' : '');

                return (
                    '<a href="' +
                    escapeHtml(item.url || indexUrl) +
                    '" class="' +
                    classes +
                    '" data-notification-id="' +
                    escapeHtml(item.id) +
                    '">' +
                    renderAvatar(item) +
                    '<div class="ob-header-notification__body">' +
                    '<div>' +
                    escapeHtml(item.message) +
                    '</div>' +
                    '<div class="ob-notification__time">' +
                    escapeHtml(item.time) +
                    '</div>' +
                    '</div>' +
                    '</a>'
                );
            })
            .join('');
    }

    function fingerprint(payload) {
        var ids = (payload.notifications || [])
            .map(function (item) {
                return item.id + ':' + (item.unread ? '1' : '0');
            })
            .join(',');

        return String(payload.unread_count) + '|' + ids;
    }

    function applyPayload(payload) {
        var next = fingerprint(payload);

        if (next === lastFingerprint) {
            return;
        }

        lastFingerprint = next;
        setBadges(payload.unread_count || 0);
        renderList(payload.notifications || []);
    }

    function poll() {
        if (inFlight || document.hidden) {
            return;
        }

        inFlight = true;

        fetch(feedUrl, {
            method: 'GET',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('feed failed');
                }

                return response.json();
            })
            .then(applyPayload)
            .catch(function () {
                // Silenzioso: un fallimento occasionale non deve disturbare l'UI.
            })
            .finally(function () {
                inFlight = false;
            });
    }

    function schedule() {
        if (timer) {
            clearInterval(timer);
        }

        timer = setInterval(poll, pollMs);
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            poll();
        }
    });

    // Dopo "segna come lette" dal pannello, allinea subito badge/lista.
    document.addEventListener('openbook:notifications-read', function () {
        setBadges(0);

        if (list) {
            list.querySelectorAll('.ob-header-notification--unread').forEach(function (el) {
                el.classList.remove('ob-header-notification--unread');
            });
        }

        lastFingerprint = null;
    });

    poll();
    schedule();
})();
