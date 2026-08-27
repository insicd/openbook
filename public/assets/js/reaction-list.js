/**
 * Elenco di chi ha messo Mi piace o ha condiviso un post: al click sul
 * numeretto apre un dropdown e carica gli attori in JSON. Senza JS il
 * pannello contiene un link alla pagina HTML dello stesso elenco.
 */
(function () {
    'use strict';

    var LIST_SELECTOR = '[data-reaction-list]';

    document.addEventListener('click', function (event) {
        var summary = event.target.closest
            ? event.target.closest(LIST_SELECTOR + ' > summary')
            : null;

        if (!summary) {
            return;
        }

        var list = summary.parentElement;

        if (list && list.classList.contains('ob-reaction-list--empty')) {
            event.preventDefault();
        }
    }, true);

    document.addEventListener('toggle', function (event) {
        var list = event.target;

        if (!list.matches || !list.matches(LIST_SELECTOR) || !list.open) {
            return;
        }

        if (list.classList.contains('ob-reaction-list--empty')) {
            list.removeAttribute('open');
            return;
        }

        loadList(list);
    }, true);

    function loadList(list) {
        if (list.dataset.loaded === '1' || list.dataset.loading === '1') {
            return;
        }

        var url = list.getAttribute('data-url');

        if (!url) {
            return;
        }

        var statusEl = list.querySelector('[data-reaction-status]');
        var actorsEl = list.querySelector('[data-reaction-actors]');
        var moreEl = list.querySelector('[data-reaction-more]');

        list.dataset.loading = '1';

        if (statusEl) {
            statusEl.hidden = false;
            statusEl.textContent = list.getAttribute('data-loading') || '';
        }

        if (actorsEl) {
            actorsEl.hidden = true;
            actorsEl.innerHTML = '';
        }

        if (moreEl) {
            moreEl.hidden = true;
            moreEl.textContent = '';
        }

        fetch(url, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('reaction list failed');
            }

            return response.json();
        }).then(function (data) {
            renderList(list, data);
            list.dataset.loaded = '1';
        }).catch(function () {
            if (statusEl) {
                statusEl.hidden = false;
                statusEl.textContent = list.getAttribute('data-error') || '';
            }
        }).finally(function () {
            delete list.dataset.loading;
        });
    }

    function renderList(list, data) {
        var statusEl = list.querySelector('[data-reaction-status]');
        var actorsEl = list.querySelector('[data-reaction-actors]');
        var moreEl = list.querySelector('[data-reaction-more]');
        var actors = Array.isArray(data.actors) ? data.actors : [];

        if (actorsEl) {
            actorsEl.innerHTML = '';
            actors.forEach(function (actor) {
                actorsEl.appendChild(actorItem(actor));
            });
            actorsEl.hidden = actors.length === 0;
        }

        if (statusEl) {
            if (actors.length === 0) {
                statusEl.hidden = false;
                statusEl.textContent = list.getAttribute('data-empty') || '';
            } else {
                statusEl.hidden = true;
            }
        }

        var remaining = typeof data.remaining === 'number' ? data.remaining : 0;

        if (moreEl) {
            if (remaining > 0) {
                var template = list.getAttribute('data-more') || '';
                moreEl.textContent = template.replace(':count', String(remaining));
                moreEl.hidden = false;
            } else {
                moreEl.hidden = true;
            }
        }
    }

    function actorItem(actor) {
        var item = document.createElement('li');
        var link = document.createElement('a');
        link.className = 'ob-reaction-list__actor';
        link.href = actor.url || '#';

        var avatar = document.createElement('div');
        avatar.className = 'ob-avatar ob-reaction-list__avatar';
        avatar.setAttribute('aria-hidden', 'true');

        if (actor.avatar_url) {
            var img = document.createElement('img');
            img.src = actor.avatar_url;
            img.alt = '';
            avatar.appendChild(img);
        } else {
            var seed = actor.name || actor.handle || '?';
            var initial = Array.from(seed)[0] || '?';
            avatar.textContent = initial.toUpperCase();
        }

        var meta = document.createElement('div');
        var nameEl = document.createElement('div');
        nameEl.className = 'ob-post__author';
        nameEl.textContent = actor.name || '';
        var handleEl = document.createElement('div');
        handleEl.className = 'ob-post__handle';
        handleEl.textContent = actor.handle || '';
        meta.appendChild(nameEl);
        meta.appendChild(handleEl);

        link.appendChild(avatar);
        link.appendChild(meta);
        item.appendChild(link);

        return item;
    }
})();
