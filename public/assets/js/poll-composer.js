/** Progressive poll fields. Native submit buttons provide the HTML fallback. */
(function () {
    'use strict';
    function toggleFor(panel) {
        return panel.closest('[data-composer]').querySelector('[data-composer-toggle="' + panel.id + '"]');
    }
    function sync(panel) {
        var rows = panel.querySelectorAll('[data-poll-option-row]');
        var active = Array.from(rows).some(function (row) {
            return row.querySelector('input').value.trim() !== '';
        });
        var toggle = toggleFor(panel);
        if (toggle) toggle.classList.toggle('is-filled', active);
        rows.forEach(function (row, index) {
            var field = row.querySelector('input');
            field.id = panel.id + '-option-' + index;
            field.required = active;
            field.setCustomValidity(Array.from(field.value.trim()).length > Number(panel.dataset.optionMaxlength)
                ? panel.dataset.optionLength : '');
            var label = row.querySelector('label');
            label.htmlFor = field.id;
            label.textContent = panel.dataset.optionLabel + ' ' + (index + 1);
            var remove = row.querySelector('[data-poll-option-remove]');
            remove.disabled = rows.length <= 2;
            remove.value = 'remove:' + index;
            remove.setAttribute('aria-label', panel.dataset.optionRemove + ' ' + (index + 1));
        });
        panel.querySelector('[data-poll-option-add]').disabled = rows.length >= Number(panel.dataset.optionLimit);
    }
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-poll-option-add], [data-poll-option-remove], [data-poll-composer-clear]');
        if (!button) return;
        var panel = button.closest('[data-poll-composer]');
        if (!panel) return;
        event.preventDefault();
        var list = panel.querySelector('[data-poll-option-list]');
        if (button.hasAttribute('data-poll-composer-clear')) {
            while (list.children.length > 2) list.lastElementChild.remove();
            list.querySelectorAll('input').forEach(function (field) { field.value = ''; });
            panel.querySelector('input[type="checkbox"]').checked = false;
            panel.querySelector('select').value = '86400';
            panel.querySelectorAll('.ob-field__error').forEach(function (error) { error.remove(); });
            var toggle = toggleFor(panel);
            if (!panel.hidden && toggle) toggle.click();
            if (toggle) toggle.focus();
        } else if (button.hasAttribute('data-poll-option-add')) {
            if (list.children.length >= Number(panel.dataset.optionLimit)) return;
            var row = list.firstElementChild.cloneNode(true);
            row.querySelector('input').value = '';
            row.querySelector('input').defaultValue = '';
            row.querySelectorAll('.ob-field__error').forEach(function (error) { error.remove(); });
            list.appendChild(row);
            sync(panel);
            row.querySelector('input').focus();
        } else if (list.children.length > 2) {
            var removed = button.closest('[data-poll-option-row]');
            var focus = removed.nextElementSibling || removed.previousElementSibling;
            removed.remove();
            focus.querySelector('input').focus();
        }
        sync(panel);
    }, true);
    document.addEventListener('reset', function (event) {
        var panel = event.target.querySelector('[data-poll-composer]');
        if (!panel) return;
        window.setTimeout(function () {
            var list = panel.querySelector('[data-poll-option-list]');
            while (list.children.length > 2) list.lastElementChild.remove();
            list.querySelectorAll('input').forEach(function (field) { field.value = ''; });
            var toggle = toggleFor(panel);
            if (!panel.hidden && toggle) toggle.click();
            sync(panel);
        }, 0);
    });
    document.addEventListener('input', function (event) {
        if (event.target.matches('[data-poll-option-input]')) sync(event.target.closest('[data-poll-composer]'));
    });
    document.addEventListener('invalid', function (event) {
        var panel = event.target.closest('[data-poll-composer]');
        if (panel && panel.hidden) {
            var toggle = toggleFor(panel);
            if (toggle) toggle.click();
        }
    }, true);
    function init() { document.querySelectorAll('[data-poll-composer]').forEach(sync); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
