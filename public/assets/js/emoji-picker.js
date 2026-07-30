/**
 * Picker emoji condiviso (composer post + commenti): apre un pannello con
 * categorie, ricerca e recenti (localStorage), e inserisce il carattere
 * Unicode nel textarea collegato tramite data-emoji-target.
 *
 * Dipende da window.OB_EMOJI_DATA (emoji-data.js: {c, n} per carattere e
 * nome CLDR). Nessuna risorsa remota. Tooltip e ricerca usano il nome.
 */
(function () {
    'use strict';

    var RECENT_KEY = 'ob_emoji_recent';
    var RECENT_MAX = 32;
    var data = window.OB_EMOJI_DATA || [];

    if (data.length === 0) {
        return;
    }

    var labels = {
        search: document.documentElement.lang && document.documentElement.lang.indexOf('it') === 0
            ? 'Cerca emoji...'
            : 'Search emoji...',
        recent: document.documentElement.lang && document.documentElement.lang.indexOf('it') === 0
            ? 'Recenti'
            : 'Recent',
        empty: document.documentElement.lang && document.documentElement.lang.indexOf('it') === 0
            ? 'Nessuna emoji trovata.'
            : 'No emoji found.',
        close: document.documentElement.lang && document.documentElement.lang.indexOf('it') === 0
            ? 'Chiudi'
            : 'Close',
    };

    // Preferisci le stringhe dal DOM se presenti (i18n Blade).
    var i18nNode = document.getElementById('ob-emoji-i18n');
    if (i18nNode) {
        labels.search = i18nNode.getAttribute('data-search') || labels.search;
        labels.recent = i18nNode.getAttribute('data-recent') || labels.recent;
        labels.empty = i18nNode.getAttribute('data-empty') || labels.empty;
        labels.close = i18nNode.getAttribute('data-close') || labels.close;
    }

    var categoryLabels = {};
    if (i18nNode) {
        try {
            categoryLabels = JSON.parse(i18nNode.getAttribute('data-categories') || '{}');
        } catch (e) {
            categoryLabels = {};
        }
    }

    var activeTrigger = null;
    var activeTarget = null;
    var activeCategory = 'recent';
    var panel = null;
    var searchInput = null;
    var grid = null;
    var tabs = null;
    var nameByChar = {};

    data.forEach(function (cat) {
        (cat.emojis || []).forEach(function (item) {
            if (item && typeof item.c === 'string' && typeof item.n === 'string') {
                nameByChar[item.c] = item.n;
            }
        });
    });

    function emojiChar(item) {
        return typeof item === 'string' ? item : (item && item.c) || '';
    }

    function emojiName(item) {
        if (typeof item === 'string') {
            return nameByChar[item] || item;
        }
        return (item && item.n) || nameByChar[item && item.c] || emojiChar(item);
    }

    function loadRecent() {
        try {
            var raw = window.localStorage.getItem(RECENT_KEY);
            var list = raw ? JSON.parse(raw) : [];
            return Array.isArray(list) ? list.filter(function (item) { return typeof item === 'string'; }) : [];
        } catch (e) {
            return [];
        }
    }

    function saveRecent(emoji) {
        var list = loadRecent().filter(function (item) { return item !== emoji; });
        list.unshift(emoji);
        if (list.length > RECENT_MAX) {
            list = list.slice(0, RECENT_MAX);
        }
        try {
            window.localStorage.setItem(RECENT_KEY, JSON.stringify(list));
        } catch (e) {
            // ignore quota / private mode
        }
    }

    function categoryTitle(id, fallbackIcon) {
        return categoryLabels[id] || fallbackIcon || id;
    }

    function buildPanel() {
        panel = document.createElement('div');
        panel.className = 'ob-emoji-picker';
        panel.id = 'ob-emoji-picker';
        panel.hidden = true;
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', 'Emoji');

        panel.innerHTML =
            '<div class="ob-emoji-picker__header">' +
                '<input type="search" class="ob-emoji-picker__search" autocomplete="off" spellcheck="false" placeholder="' + escapeAttr(labels.search) + '">' +
                '<button type="button" class="ob-icon-btn ob-emoji-picker__close" aria-label="' + escapeAttr(labels.close) + '">' +
                    '<span aria-hidden="true">&times;</span>' +
                '</button>' +
            '</div>' +
            '<div class="ob-emoji-picker__tabs" role="tablist"></div>' +
            '<div class="ob-emoji-picker__grid" role="listbox"></div>';

        document.body.appendChild(panel);

        searchInput = panel.querySelector('.ob-emoji-picker__search');
        grid = panel.querySelector('.ob-emoji-picker__grid');
        tabs = panel.querySelector('.ob-emoji-picker__tabs');

        panel.querySelector('.ob-emoji-picker__close').addEventListener('click', close);

        searchInput.addEventListener('input', function () {
            renderGrid();
        });

        buildTabs();
    }

    function escapeAttr(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;');
    }

    function buildTabs() {
        tabs.innerHTML = '';

        var items = [{ id: 'recent', icon: '🕒' }].concat(data.map(function (cat) {
            return { id: cat.id, icon: cat.icon };
        }));

        items.forEach(function (item) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'ob-emoji-picker__tab';
            button.setAttribute('role', 'tab');
            button.setAttribute('data-category', item.id);
            button.setAttribute('aria-label', categoryTitle(item.id, item.icon));
            button.title = categoryTitle(item.id, item.icon);
            button.textContent = item.icon;
            button.addEventListener('click', function () {
                activeCategory = item.id;
                searchInput.value = '';
                syncTabs();
                renderGrid();
            });
            tabs.appendChild(button);
        });
    }

    function syncTabs() {
        Array.prototype.forEach.call(tabs.querySelectorAll('.ob-emoji-picker__tab'), function (tab) {
            var selected = tab.getAttribute('data-category') === activeCategory;
            tab.classList.toggle('is-active', selected);
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
        });
    }

    function currentEmojis() {
        var query = (searchInput.value || '').trim().toLowerCase();

        if (query !== '') {
            var matches = [];
            var seen = {};

            data.forEach(function (cat) {
                var label = (categoryLabels[cat.id] || '').toLowerCase();
                var matchesCategory = cat.id.indexOf(query) !== -1 || label.indexOf(query) !== -1;

                (cat.emojis || []).forEach(function (item) {
                    var char = emojiChar(item);
                    var name = emojiName(item).toLowerCase();
                    if (!char || seen[char]) {
                        return;
                    }
                    if (
                        matchesCategory
                        || name.indexOf(query) !== -1
                        || char.indexOf(query) !== -1
                    ) {
                        seen[char] = true;
                        matches.push(item);
                    }
                });
            });

            return matches;
        }

        if (activeCategory === 'recent') {
            return loadRecent().map(function (char) {
                return { c: char, n: nameByChar[char] || char };
            });
        }

        var cat = data.find(function (item) { return item.id === activeCategory; });
        return cat ? cat.emojis.slice() : [];
    }

    function renderGrid() {
        var emojis = currentEmojis();
        grid.innerHTML = '';

        if (emojis.length === 0) {
            var empty = document.createElement('p');
            empty.className = 'ob-emoji-picker__empty';
            empty.textContent = labels.empty;
            grid.appendChild(empty);
            return;
        }

        var fragment = document.createDocumentFragment();
        emojis.forEach(function (item) {
            var char = emojiChar(item);
            var name = emojiName(item);
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'ob-emoji-picker__emoji';
            button.setAttribute('role', 'option');
            button.setAttribute('aria-label', name);
            button.title = name;
            button.textContent = char;
            button.addEventListener('click', function () {
                insertEmoji(char);
            });
            fragment.appendChild(button);
        });
        grid.appendChild(fragment);
    }

    function insertEmoji(emoji) {
        if (!activeTarget) {
            return;
        }

        var field = activeTarget;
        var start = typeof field.selectionStart === 'number' ? field.selectionStart : field.value.length;
        var end = typeof field.selectionEnd === 'number' ? field.selectionEnd : field.value.length;
        var value = field.value || '';
        var max = field.maxLength > 0 ? field.maxLength : null;

        if (max !== null && (value.length - (end - start) + emoji.length) > max) {
            return;
        }

        field.value = value.slice(0, start) + emoji + value.slice(end);
        var caret = start + emoji.length;
        field.focus();
        if (typeof field.setSelectionRange === 'function') {
            field.setSelectionRange(caret, caret);
        }

        field.dispatchEvent(new Event('input', { bubbles: true }));
        saveRecent(emoji);
    }

    function positionPanel(trigger) {
        var rect = trigger.getBoundingClientRect();
        var panelWidth = Math.min(320, window.innerWidth - 16);
        var panelHeight = 360;
        var left = rect.left + (rect.width / 2) - (panelWidth / 2);
        left = Math.max(8, Math.min(left, window.innerWidth - panelWidth - 8));

        var spaceBelow = window.innerHeight - rect.bottom;
        var top;
        if (spaceBelow < panelHeight + 12 && rect.top > spaceBelow) {
            top = rect.top - panelHeight - 8;
        } else {
            top = rect.bottom + 8;
        }
        top = Math.max(8, Math.min(top, window.innerHeight - Math.min(panelHeight, window.innerHeight - 16) - 8));

        panel.style.width = panelWidth + 'px';
        panel.style.left = left + 'px';
        panel.style.top = top + 'px';
    }

    function open(trigger) {
        var targetId = trigger.getAttribute('data-emoji-target');
        var target = targetId ? document.getElementById(targetId) : null;
        if (!target) {
            return;
        }

        if (!panel) {
            buildPanel();
        }

        activeTrigger = trigger;
        activeTarget = target;
        activeCategory = loadRecent().length > 0 ? 'recent' : data[0].id;
        searchInput.value = '';
        panel.hidden = false;
        syncTabs();
        renderGrid();
        positionPanel(trigger);
        trigger.setAttribute('aria-expanded', 'true');
        searchInput.focus();
    }

    function close() {
        if (!panel || panel.hidden) {
            return;
        }

        panel.hidden = true;
        if (activeTrigger) {
            activeTrigger.setAttribute('aria-expanded', 'false');
            activeTrigger.focus();
        }
        activeTrigger = null;
        activeTarget = null;
    }

    function toggle(trigger) {
        if (activeTrigger === trigger && panel && !panel.hidden) {
            close();
            return;
        }
        open(trigger);
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-emoji-target]');
        if (trigger) {
            event.preventDefault();
            toggle(trigger);
            return;
        }

        if (panel && !panel.hidden && !panel.contains(event.target)) {
            close();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && panel && !panel.hidden) {
            close();
        }
    });

    window.addEventListener('resize', function () {
        if (panel && !panel.hidden && activeTrigger) {
            positionPanel(activeTrigger);
        }
    });
})();
